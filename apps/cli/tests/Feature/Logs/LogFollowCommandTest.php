<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\InterruptIntent;
use App\Support\Logs\FakeLogFollowClock;
use App\Support\Logs\LogFollowClock;
use App\Support\Realtime\FakeWebSocketTransport;
use App\Support\Realtime\RealtimeConnectionException;
use App\Support\Realtime\WebSocketTransport;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AppInstances\InstanceLogsRequest;
use Orbit\Sdk\Requests\Logs\CreateLogStreamRequest;
use Orbit\Sdk\Requests\Logs\DestroyLogStreamRequest;
use Orbit\Sdk\Requests\Logs\RenewLogStreamRequest;
use Orbit\Sdk\Requests\Processes\ProcessLogsRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    InterruptIntent::clear();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-log-follow-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    $this->clock = new FakeLogFollowClock;
    app()->instance(LogFollowClock::class, $this->clock);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    InterruptIntent::clear();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('process:logs --follow', function (): void {
    it('opens a stream, activates it once subscribed, prints its lines and markers, renews it, and closes it on Ctrl-C', function (): void {
        log_follow_profile();
        $transport = log_follow_transport(log_follow_handshake('123.456', log_follow_stream_id('a')));
        $timeline = [];
        $start = $this->clock->now();
        $mock = MockClient::global([
            CreateLogStreamRequest::class => function () use (&$timeline, $transport): MockResponse {
                $timeline[] = 'create with '.count($transport->sent).' subscriptions sent';

                return MockResponse::make(log_follow_stream_envelope('a'), 201);
            },
            RenewLogStreamRequest::class => function () use (&$timeline, $transport, $start): MockResponse {
                $timeline[] = sprintf('renew at %ds with %d subscriptions sent', round($this->clock->now() - $start), count($transport->sent));

                if (count($timeline) === 2) {
                    // The Gateway lets the agent read an inactive stream only after this first renewal.
                    $transport->enqueue([
                        log_follow_lines('a', 1, ['first', "second\e[31m"]),
                        null,
                        log_follow_lines('a', 2, ['third'], dropped: 120, skipped: 5 * 1_048_576),
                    ]);
                }

                return log_follow_renewed('a');
            },
            DestroyLogStreamRequest::class => MockResponse::make([
                'data' => ['id' => log_follow_stream_id('a'), 'closed' => true],
                'meta' => ['request_id' => log_follow_request_id()],
            ]),
        ]);
        $this->clock->onTick(function (float $now) use ($start): void {
            if ($now - $start >= 21.0) {
                InterruptIntent::record(SIGINT);
            }
        });

        $exitCode = Artisan::call('process:logs', ['process' => '41', '--follow' => true]);

        expect($timeline)->toBe([
            'create with 0 subscriptions sent',
            'renew at 0s with 1 subscriptions sent',
            'renew at 20s with 1 subscriptions sent',
        ]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toBe(
                "first\nsecond\\u{001B}[31m\n[orbit] 120 lines dropped\n[orbit] 5.0 MiB skipped\nthird\n",
            );

        $create = log_follow_sent($mock, CreateLogStreamRequest::class);
        expect($create)->toHaveCount(1)
            ->and($create[0]->getUrl())->toBe('https://10.44.0.1/api/v1/processes/41/log-streams')
            ->and($create[0]->body()?->all())->toBe(['socket_id' => '123.456', 'lines' => 100])
            ->and(log_follow_sent($mock, RenewLogStreamRequest::class))->toHaveCount(2)
            ->and(log_follow_sent($mock, RenewLogStreamRequest::class)[0]->getUrl())
            ->toBe('https://10.44.0.1/api/v1/processes/41/log-streams/'.log_follow_stream_id('a'))
            ->and(log_follow_sent($mock, DestroyLogStreamRequest::class))->toHaveCount(1)
            ->and($transport->sent)->toBe([[
                'event' => 'pusher:subscribe',
                'data' => ['auth' => 'app-key:log-signature', 'channel' => 'private-log-stream.'.log_follow_stream_id('a')],
            ]]);
    });

    it('waits for the subscription to succeed before the activating renewal', function (): void {
        log_follow_profile();
        [$established, $subscribed] = log_follow_handshake('1.2', log_follow_stream_id('a'));
        $transport = log_follow_transport([$established]);
        $renewedAfterTicks = null;
        MockClient::global([
            ...log_follow_stream_mocks('a'),
            RenewLogStreamRequest::class => function () use (&$renewedAfterTicks): MockResponse {
                $renewedAfterTicks ??= $this->clock->sleeps;

                return log_follow_renewed('a');
            },
        ]);
        $this->clock->onTick(function (float $now, int $count) use ($transport, $subscribed): void {
            if ($count === 3) {
                $transport->enqueue([$subscribed]);
            }

            if ($count === 5) {
                InterruptIntent::record(SIGINT);
            }
        });

        expect(Artisan::call('process:logs', ['process' => '41', '--follow' => true]))->toBe(0)
            ->and($renewedAfterTicks)->toBe(3);
    });

    it('ignores client events and lines for another stream on the channel', function (): void {
        log_follow_profile();
        log_follow_transport([
            ...log_follow_handshake('1.2', log_follow_stream_id('a')),
            ['event' => 'client-log', 'channel' => 'private-log-stream.'.log_follow_stream_id('a'), 'data' => ['lines' => ['forged']]],
            log_follow_lines('b', 1, ['other stream'], channelStream: 'a'),
            log_follow_lines('a', 1, ['kept']),
        ]);
        MockClient::global(log_follow_stream_mocks('a'));
        log_follow_interrupt_after($this->clock, 3);

        $exitCode = Artisan::call('process:logs', ['process' => '41', '--follow' => true]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toBe("kept\n");
    });

    it('prints a repeated part once', function (): void {
        log_follow_profile();
        log_follow_transport([
            ...log_follow_handshake('1.2', log_follow_stream_id('a')),
            log_follow_lines('a', 1, ['one']),
            log_follow_lines('a', 2, ['two']),
            log_follow_lines('a', 2, ['two']),
            log_follow_lines('a', 3, ['three']),
        ]);
        MockClient::global(log_follow_stream_mocks('a'));
        log_follow_interrupt_after($this->clock, 3);

        expect(Artisan::call('process:logs', ['process' => '41', '--follow' => true]))->toBe(0)
            ->and(Artisan::output())->toBe("one\ntwo\nthree\n");
    });

    it('polls the one-shot read every 5 seconds when the Gateway refuses a live stream', function (): void {
        log_follow_profile();
        log_follow_transport(log_follow_handshake('1.2', log_follow_stream_id('a')));
        $reads = ["a\nb\nc\n", "b\nc\nd\ne\n", "c\nd\ne\n"];
        $mock = MockClient::global([
            CreateLogStreamRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'logs.live_unavailable',
                    'message' => 'Live logs are not available for this Node.',
                    'details' => ['reason' => 'agent_outdated'],
                ],
            ], 409),
            ProcessLogsRequest::class => log_follow_reads($reads),
        ]);
        $sleeps = [];
        $this->clock->onTick(function (float $now, int $count) use (&$sleeps): void {
            $sleeps[] = $now;

            if ($count === 4) {
                InterruptIntent::record(SIGINT);
            }
        });

        $exitCode = Artisan::call('process:logs', ['process' => '41', '--follow' => true, '--lines' => '3']);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toBe(
                "Live tail unavailable (agent_outdated); polling every 5 seconds.\na\nb\nc\nd\ne\n",
            )
            ->and(array_map(fn (float $at): float => $at - $sleeps[0], $sleeps))->toBe([0.0, 5.0, 10.0, 15.0])
            ->and(log_follow_sent($mock, ProcessLogsRequest::class))->toHaveCount(3)
            ->and(log_follow_sent($mock, ProcessLogsRequest::class)[0]->query()->all())->toBe(['lines' => 3])
            ->and(log_follow_sent($mock, DestroyLogStreamRequest::class))->toBe([]);
    });

    it('switches to polling and prints only unseen lines when the agent leaves', function (): void {
        log_follow_profile();
        log_follow_transport([
            ...log_follow_handshake('1.2', log_follow_stream_id('a')),
            log_follow_lines('a', 1, ['w', 'x', 'y']),
            log_follow_ended('a', 'agent_left'),
        ]);
        MockClient::global([
            ...log_follow_stream_mocks('a'),
            ProcessLogsRequest::class => log_follow_reads(["v\nw\nx\ny\nz\n"]),
        ]);
        log_follow_interrupt_after($this->clock, 2);

        $exitCode = Artisan::call('process:logs', ['process' => '41', '--follow' => true, '--json' => true]);

        expect($exitCode)->toBe(0)
            ->and(log_follow_json_lines(Artisan::output()))->toBe([
                ['type' => 'lines', 'lines' => ['w', 'x', 'y'], 'dropped' => 0, 'skipped' => 0],
                ['type' => 'notice', 'code' => 'logs.live_unavailable', 'reason' => 'agent_left'],
                ['type' => 'lines', 'lines' => ['z'], 'dropped' => 0, 'skipped' => 0],
            ]);
    });

    it('opens a new stream after the lease expires and skips the lines it already printed', function (): void {
        log_follow_profile();
        $transport = log_follow_transport([
            ...log_follow_handshake('1.1', log_follow_stream_id('a')),
            null,
            log_follow_lines('a', 1, ['a', 'b']),
            log_follow_ended('a', 'expired'),
            null,
            ...log_follow_handshake('2.2', log_follow_stream_id('b')),
            log_follow_lines('b', 1, ['a']),
            log_follow_lines('b', 2, ['b', 'c']),
            log_follow_lines('b', 3, ['d']),
        ]);
        $streams = ['a', 'b'];
        $mock = MockClient::global([
            CreateLogStreamRequest::class => function () use (&$streams): MockResponse {
                return MockResponse::make(log_follow_stream_envelope(array_shift($streams) ?? 'z'), 201);
            },
            RenewLogStreamRequest::class => log_follow_renewed('a'),
            DestroyLogStreamRequest::class => MockResponse::make([
                'data' => ['id' => log_follow_stream_id('b'), 'closed' => true],
                'meta' => ['request_id' => log_follow_request_id()],
            ]),
        ]);
        log_follow_interrupt_after($this->clock, 4);

        $exitCode = Artisan::call('process:logs', ['process' => '41', '--follow' => true]);

        $create = log_follow_sent($mock, CreateLogStreamRequest::class);
        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toBe("a\nb\nc\nd\n")
            ->and(array_map(fn (PendingRequest $request): mixed => $request->body()?->all(), $create))->toBe([
                ['socket_id' => '1.1', 'lines' => 100],
                // Once lines are printed, a reopened stream reaches back far enough to find them.
                ['socket_id' => '2.2', 'lines' => 1000],
            ])
            ->and(count($transport->connections))->toBe(2)
            ->and(array_map(fn (PendingRequest $request): string => $request->getUrl(), log_follow_sent($mock, RenewLogStreamRequest::class)))->toBe([
                'https://10.44.0.1/api/v1/processes/41/log-streams/'.log_follow_stream_id('a'),
                'https://10.44.0.1/api/v1/processes/41/log-streams/'.log_follow_stream_id('b'),
            ])
            ->and(log_follow_sent($mock, DestroyLogStreamRequest::class))->toHaveCount(1)
            ->and(log_follow_sent($mock, DestroyLogStreamRequest::class)[0]->getUrl())
            ->toEndWith('/log-streams/'.log_follow_stream_id('b'));
    });

    it('reconnects after the socket drops, closes the old stream, and skips lines it already printed', function (): void {
        log_follow_profile();
        $transport = log_follow_transport([
            ...log_follow_handshake('1.1', log_follow_stream_id('a')),
            log_follow_lines('a', 1, ['a', 'b']),
            null,
            new RealtimeConnectionException('The realtime socket disconnected.'),
            ...log_follow_handshake('2.2', log_follow_stream_id('b')),
            log_follow_lines('b', 1, ['a', 'b', 'c']),
        ]);
        $streams = ['a', 'b'];
        $mock = MockClient::global([
            CreateLogStreamRequest::class => function () use (&$streams): MockResponse {
                return MockResponse::make(log_follow_stream_envelope(array_shift($streams) ?? 'z'), 201);
            },
            RenewLogStreamRequest::class => log_follow_renewed('a'),
            DestroyLogStreamRequest::class => MockResponse::make([
                'data' => ['id' => log_follow_stream_id('a'), 'closed' => true],
                'meta' => ['request_id' => log_follow_request_id()],
            ]),
        ]);
        log_follow_interrupt_after($this->clock, 15);

        $exitCode = Artisan::call('process:logs', ['process' => '41', '--follow' => true]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toBe("a\nb\nLive tail disconnected. Reconnecting…\nc\n")
            ->and(count($transport->connections))->toBe(2)
            ->and(array_map(
                fn (PendingRequest $request): string => $request->getUrl(),
                log_follow_sent($mock, DestroyLogStreamRequest::class),
            ))->toBe([
                'https://10.44.0.1/api/v1/processes/41/log-streams/'.log_follow_stream_id('a'),
                'https://10.44.0.1/api/v1/processes/41/log-streams/'.log_follow_stream_id('b'),
            ]);
    });

    it('polls after 15 seconds when the realtime socket never connects', function (bool $json, string $notice): void {
        log_follow_profile();
        $transport = new class implements WebSocketTransport
        {
            public int $attempts = 0;

            public function connect(string $url, ?string $caPath = null, float $timeoutSeconds = 10.0): void
            {
                $this->attempts++;

                throw new RealtimeConnectionException('Could not resolve reverb.orbit.');
            }

            public function send(array $message): void {}

            public function receive(): ?array
            {
                return null;
            }

            public function close(): void {}

            public function isConnected(): bool
            {
                return false;
            }
        };
        app()->instance(WebSocketTransport::class, $transport);
        $mock = MockClient::global([
            ...log_follow_stream_mocks('a'),
            ProcessLogsRequest::class => log_follow_reads(["a\nb\n", "a\nb\nc\n"]),
        ]);
        $start = $this->clock->now();
        $lastUnpolledTick = null;
        $this->clock->onTick(function (float $now) use ($start, $mock, &$lastUnpolledTick): void {
            $polls = count(log_follow_sent($mock, ProcessLogsRequest::class));

            if ($polls === 0) {
                $lastUnpolledTick = round($now - $start);
            }

            if ($polls === 2) {
                InterruptIntent::record(SIGINT);
            }
        });

        $exitCode = Artisan::call('process:logs', ['process' => '41', '--follow' => true, '--json' => $json]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toBe($json
                ? $notice."\n".'{"type":"lines","lines":["a","b"],"dropped":0,"skipped":0}'."\n".'{"type":"lines","lines":["c"],"dropped":0,"skipped":0}'."\n"
                : $notice."\na\nb\nc\n")
            ->and($lastUnpolledTick)->toBe(15.0)
            ->and($transport->attempts)->toBeGreaterThan(1)
            ->and(log_follow_sent($mock, CreateLogStreamRequest::class))->toBe([]);
    })->with([
        'json' => [true, '{"type":"notice","code":"logs.live_unavailable","reason":"realtime_unreachable"}'],
        'human' => [false, 'Live tail unavailable (realtime unreachable); polling every 5 seconds.'],
    ]);

    it('polls when the socket does not reconnect within 15 seconds of a drop', function (): void {
        log_follow_profile();
        $transport = log_follow_transport([
            ...log_follow_handshake('1.1', log_follow_stream_id('a')),
            null,
            log_follow_lines('a', 1, ['a', 'b']),
            null,
            new RealtimeConnectionException('The realtime socket disconnected.'),
        ]);
        $mock = MockClient::global([
            ...log_follow_stream_mocks('a'),
            ProcessLogsRequest::class => log_follow_reads(["a\nb\nc\n"]),
        ]);
        $start = $this->clock->now();
        $this->clock->onTick(function (float $now) use ($mock): void {
            if (log_follow_sent($mock, ProcessLogsRequest::class) !== []) {
                InterruptIntent::record(SIGINT);
            }
        });

        $exitCode = Artisan::call('process:logs', ['process' => '41', '--follow' => true, '--json' => true]);

        expect($exitCode)->toBe(0)
            ->and(log_follow_json_lines(Artisan::output()))->toBe([
                ['type' => 'lines', 'lines' => ['a', 'b'], 'dropped' => 0, 'skipped' => 0],
                ['type' => 'notice', 'code' => 'logs.live_unavailable', 'reason' => 'realtime_unreachable'],
                ['type' => 'lines', 'lines' => ['c'], 'dropped' => 0, 'skipped' => 0],
            ])
            ->and(round($this->clock->now() - $start))->toBeGreaterThanOrEqual(15.0)
            ->and(count($transport->connections))->toBeGreaterThan(1)
            ->and(log_follow_sent($mock, DestroyLogStreamRequest::class))->toHaveCount(1);
    });

    it('stops with node_access.required when the Gateway revokes the stream', function (): void {
        log_follow_profile();
        log_follow_transport([
            ...log_follow_handshake('1.2', log_follow_stream_id('a')),
            log_follow_lines('a', 1, ['secret-free line']),
            log_follow_ended('a', 'revoked'),
        ]);
        $mock = MockClient::global(log_follow_stream_mocks('a'));
        log_follow_interrupt_after($this->clock, 5);

        $exitCode = Artisan::call('process:logs', ['process' => '41', '--follow' => true, '--json' => true]);

        expect($exitCode)->toBe(1)
            ->and(log_follow_json_lines(Artisan::output()))->toBe([
                ['type' => 'lines', 'lines' => ['secret-free line'], 'dropped' => 0, 'skipped' => 0],
                ['error' => ['code' => 'node_access.required', 'message' => 'Node access is required.', 'request_id' => null]],
            ])
            ->and(log_follow_sent($mock, DestroyLogStreamRequest::class))->toBe([]);
    });

    it('exits 0 when the Gateway reports the stream closed', function (): void {
        log_follow_profile();
        log_follow_transport([
            ...log_follow_handshake('1.2', log_follow_stream_id('a')),
            log_follow_ended('a', 'closed'),
        ]);
        MockClient::global(log_follow_stream_mocks('a'));

        expect(Artisan::call('process:logs', ['process' => '41', '--follow' => true]))->toBe(0)
            ->and(Artisan::output())->toBe('');
    });

    it('polls with a stream_limit notice when the serving Node has no stream left', function (bool $json, string $expected): void {
        log_follow_profile();
        log_follow_transport(log_follow_handshake('1.2', log_follow_stream_id('a')));
        MockClient::global([
            CreateLogStreamRequest::class => MockResponse::make([
                'error' => ['code' => 'logs.stream_limit', 'message' => 'The Node already has 16 open log streams.', 'details' => []],
            ], 429, ['X-Orbit-Request-Id' => log_follow_request_id()]),
            ProcessLogsRequest::class => log_follow_reads(["only line\n"]),
        ]);
        log_follow_interrupt_after($this->clock, 2);

        $exitCode = Artisan::call('process:logs', ['process' => '41', '--follow' => true, '--json' => $json]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toBe($expected);
    })->with([
        'json' => [true, '{"type":"notice","code":"logs.stream_limit","reason":null}'."\n".'{"type":"lines","lines":["only line"],"dropped":0,"skipped":0}'."\n"],
        'human' => [false, "Live tail unavailable (stream limit reached); polling every 5 seconds.\nonly line\n"],
    ]);

    it('fails with the Gateway error when opening the stream is refused for another reason', function (): void {
        log_follow_profile();
        log_follow_transport(log_follow_handshake('1.2', log_follow_stream_id('a')));
        MockClient::global([
            CreateLogStreamRequest::class => MockResponse::make([
                'error' => ['code' => 'node_access.required', 'message' => 'Node access is required.', 'details' => []],
            ], 403, ['X-Orbit-Request-Id' => log_follow_request_id()]),
        ]);

        $exitCode = Artisan::call('process:logs', ['process' => '41', '--follow' => true, '--json' => true]);

        expect($exitCode)->toBe(1)
            ->and(log_follow_json_lines(Artisan::output()))->toBe([[
                'error' => [
                    'code' => 'node_access.required',
                    'message' => 'Node access is required.',
                    'request_id' => log_follow_request_id(),
                ],
            ]]);
    });

    it('reopens the stream when a renewal finds it gone', function (): void {
        log_follow_profile();
        $transport = log_follow_transport([
            ...log_follow_handshake('1.1', log_follow_stream_id('a')),
            log_follow_lines('a', 1, ['a']),
        ]);
        $streams = ['a', 'b'];
        $renewals = 0;
        $mock = MockClient::global([
            CreateLogStreamRequest::class => function () use (&$streams): MockResponse {
                return MockResponse::make(log_follow_stream_envelope(array_shift($streams) ?? 'z'), 201);
            },
            RenewLogStreamRequest::class => function () use ($transport, &$renewals): MockResponse {
                // The first renewal activates stream a, the second finds it gone, the third activates stream b.
                if (++$renewals !== 2) {
                    return log_follow_renewed('a');
                }

                $transport->enqueue([...log_follow_handshake('2.2', log_follow_stream_id('b')), log_follow_lines('b', 1, ['a', 'b'])]);

                return MockResponse::make([
                    'error' => ['code' => 'logs.stream_not_found', 'message' => 'The log stream does not exist.', 'details' => []],
                ], 404);
            },
            DestroyLogStreamRequest::class => MockResponse::make([
                'data' => ['id' => log_follow_stream_id('b'), 'closed' => true],
                'meta' => ['request_id' => log_follow_request_id()],
            ]),
        ]);
        $start = $this->clock->now();
        $this->clock->onTick(function (float $now) use ($start): void {
            if ($now - $start >= 21.0) {
                InterruptIntent::record(SIGINT);
            }
        });

        $exitCode = Artisan::call('process:logs', ['process' => '41', '--follow' => true]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toBe("a\nb\n")
            ->and(log_follow_sent($mock, CreateLogStreamRequest::class))->toHaveCount(2)
            ->and($renewals)->toBe(3);
    });

    it('polls with a notice when realtime is not configured anywhere', function (): void {
        app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1'));
        $transport = log_follow_transport([]);
        MockClient::global([
            ShowRealtimeRequest::class => MockResponse::make([
                'data' => ['url' => null, 'key' => null, 'channel' => 'orbit'],
                'meta' => ['request_id' => log_follow_request_id()],
            ]),
            ProcessLogsRequest::class => log_follow_reads(["only line\n"]),
        ]);
        log_follow_interrupt_after($this->clock, 1);

        $exitCode = Artisan::call('process:logs', ['process' => '41', '--follow' => true, '--json' => true]);

        expect($exitCode)->toBe(0)
            ->and(log_follow_json_lines(Artisan::output()))->toBe([
                ['type' => 'notice', 'code' => 'logs.live_unavailable', 'reason' => 'realtime_not_configured'],
                ['type' => 'lines', 'lines' => ['only line'], 'dropped' => 0, 'skipped' => 0],
            ])
            ->and($transport->connections)->toBe([]);
    });

    it('prints each new line once when it polls with --lines=1', function (): void {
        log_follow_profile();
        log_follow_transport(log_follow_handshake('1.2', log_follow_stream_id('a')));
        $file = ['prod-line-1', 'prod-line-2'];
        $mock = MockClient::global([
            CreateLogStreamRequest::class => log_follow_refusal('ssh_only'),
            ProcessLogsRequest::class => log_follow_file($file),
        ]);
        $this->clock->onTick(function (float $now, int $count) use (&$file): void {
            // The first poll runs after the first sleep; each later sleep ends five seconds on.
            match ($count) {
                2 => $file[] = 'A',
                3 => $file[] = 'B',
                default => null,
            };

            if ($count === 7) {
                InterruptIntent::record(SIGINT);
            }
        });

        expect(Artisan::call('process:logs', ['process' => '41', '--follow' => true, '--lines' => '1']))->toBe(0)
            ->and(Artisan::output())->toBe("Following over SSH; polling every 5 seconds.\nprod-line-2\nA\nB\n")
            ->and(array_map(
                fn (PendingRequest $request): mixed => $request->query()->get('lines'),
                log_follow_sent($mock, ProcessLogsRequest::class),
            ))->toBe([1, 1000, 1000, 1000, 1000, 1000]);
    });

    it('marks a gap and prints the newest lines when more lines arrive between polls than the window holds', function (): void {
        log_follow_profile();
        log_follow_transport(log_follow_handshake('1.2', log_follow_stream_id('a')));
        $file = ['start'];
        MockClient::global([
            CreateLogStreamRequest::class => log_follow_refusal('ssh_only'),
            ProcessLogsRequest::class => log_follow_file($file),
        ]);
        $this->clock->onTick(function (float $now, int $count) use (&$file): void {
            if ($count === 2) {
                array_push($file, ...array_map(static fn (int $i): string => "burst {$i}", range(1, 1500)));
            }

            if ($count === 3) {
                $file[] = 'after';
            }

            if ($count === 5) {
                InterruptIntent::record(SIGINT);
            }
        });

        Artisan::call('process:logs', ['process' => '41', '--follow' => true, '--lines' => '2', '--json' => true]);

        // After the gap the follow finds its place again, so later polls print only new lines.
        expect(log_follow_json_lines(Artisan::output()))->toBe([
            ['type' => 'notice', 'code' => 'logs.live_unavailable', 'reason' => 'ssh_only'],
            ['type' => 'lines', 'lines' => ['start'], 'dropped' => 0, 'skipped' => 0],
            ['type' => 'missing'],
            ['type' => 'lines', 'lines' => ['burst 1499', 'burst 1500'], 'dropped' => 0, 'skipped' => 0],
            ['type' => 'lines', 'lines' => ['after'], 'dropped' => 0, 'skipped' => 0],
        ]);
    });

    it('prints repeated identical lines of a short log by their position', function (): void {
        log_follow_profile();
        log_follow_transport(log_follow_handshake('1.2', log_follow_stream_id('a')));
        $file = ['tick', 'tick'];
        MockClient::global([
            CreateLogStreamRequest::class => log_follow_refusal('ssh_only'),
            ProcessLogsRequest::class => log_follow_file($file),
        ]);
        $this->clock->onTick(function (float $now, int $count) use (&$file): void {
            match ($count) {
                2 => array_push($file, 'tick', 'tick', 'tick'),
                3 => $file[] = 'tick',
                default => null,
            };

            if ($count === 4) {
                InterruptIntent::record(SIGINT);
            }
        });

        Artisan::call('process:logs', ['process' => '41', '--follow' => true]);

        expect(Artisan::output())->toBe("Following over SSH; polling every 5 seconds.\n".str_repeat("tick\n", 6));
    });

    it('retries the live path every 30 seconds while the agent has not joined its log channel', function (): void {
        log_follow_profile();
        $transport = log_follow_transport([log_follow_handshake('1.1', log_follow_stream_id('a'))[0]]);
        $file = ['one', 'two'];
        $creates = 0;
        $renewed = false;
        $mock = MockClient::global([
            CreateLogStreamRequest::class => function () use (&$creates, $transport): MockResponse {
                if (++$creates < 3) {
                    // The next connection, after the retry.
                    $transport->enqueue([log_follow_handshake(($creates + 1).'.1', log_follow_stream_id('b'))[0]]);

                    return log_follow_refusal('agent_not_joined');
                }

                $transport->enqueue([log_follow_handshake('3.1', log_follow_stream_id('b'))[1]]);

                return MockResponse::make(log_follow_stream_envelope('b'), 201);
            },
            RenewLogStreamRequest::class => function () use ($transport, &$file, &$renewed): MockResponse {
                if (! $renewed) {
                    $renewed = true;
                    // The new stream's first lines reach back past the lines the polls printed.
                    $transport->enqueue([log_follow_lines('b', 1, [...$file, 'live'])]);
                }

                return log_follow_renewed('b');
            },
            DestroyLogStreamRequest::class => MockResponse::make([
                'data' => ['id' => log_follow_stream_id('b'), 'closed' => true],
                'meta' => ['request_id' => log_follow_request_id()],
            ]),
            ProcessLogsRequest::class => log_follow_file($file),
        ]);
        $start = $this->clock->now();
        $this->clock->onTick(function (float $now) use ($start, &$file, &$renewed): void {
            if ($now - $start >= 12.0 && ! in_array('three', $file, strict: true)) {
                $file[] = 'three';
            }

            if ($renewed && $now - $start >= 62.0) {
                InterruptIntent::record(SIGINT);
            }
        });

        expect(Artisan::call('process:logs', ['process' => '41', '--follow' => true, '--json' => true]))->toBe(0)
            ->and(log_follow_json_lines(Artisan::output()))->toBe([
                ['type' => 'notice', 'code' => 'logs.live_unavailable', 'reason' => 'agent_not_joined'],
                ['type' => 'lines', 'lines' => ['one', 'two'], 'dropped' => 0, 'skipped' => 0],
                ['type' => 'lines', 'lines' => ['three'], 'dropped' => 0, 'skipped' => 0],
                ['type' => 'lines', 'lines' => ['live'], 'dropped' => 0, 'skipped' => 0],
            ])
            ->and(array_map(
                fn (PendingRequest $request): mixed => $request->body()?->all()['lines'] ?? null,
                log_follow_sent($mock, CreateLogStreamRequest::class),
            ))->toBe([100, 1000, 1000])
            ->and(count($transport->connections))->toBe(3);
    });

    it('keeps polling without a live retry when the agent is older than 0.3.0', function (): void {
        log_follow_profile();
        log_follow_transport(log_follow_handshake('1.2', log_follow_stream_id('a')));
        $mock = MockClient::global([
            CreateLogStreamRequest::class => log_follow_refusal('agent_outdated'),
            ProcessLogsRequest::class => log_follow_reads(["a\n"]),
        ]);
        $start = $this->clock->now();
        $this->clock->onTick(function (float $now) use ($start): void {
            if ($now - $start >= 95.0) {
                InterruptIntent::record(SIGINT);
            }
        });

        expect(Artisan::call('process:logs', ['process' => '41', '--follow' => true]))->toBe(0)
            ->and(log_follow_sent($mock, CreateLogStreamRequest::class))->toHaveCount(1);
    });

    it('marks a gap when a reopened stream does not reach the lines printed last', function (): void {
        log_follow_profile();
        log_follow_transport([
            ...log_follow_handshake('1.1', log_follow_stream_id('a')),
            null,
            log_follow_lines('a', 1, ['a', 'b']),
            log_follow_ended('a', 'expired'),
            null,
            ...log_follow_handshake('2.2', log_follow_stream_id('b')),
            log_follow_lines('b', 1, ['x', 'y', 'z']),
        ]);
        $streams = ['a', 'b'];
        MockClient::global([
            CreateLogStreamRequest::class => function () use (&$streams): MockResponse {
                return MockResponse::make(log_follow_stream_envelope(array_shift($streams) ?? 'z'), 201);
            },
            RenewLogStreamRequest::class => log_follow_renewed('a'),
            DestroyLogStreamRequest::class => MockResponse::make([
                'data' => ['id' => log_follow_stream_id('b'), 'closed' => true],
                'meta' => ['request_id' => log_follow_request_id()],
            ]),
        ]);
        $start = $this->clock->now();
        $this->clock->onTick(function (float $now) use ($start): void {
            if ($now - $start >= 5.0) {
                InterruptIntent::record(SIGINT);
            }
        });

        expect(Artisan::call('process:logs', ['process' => '41', '--follow' => true, '--lines' => '2']))->toBe(0)
            ->and(Artisan::output())->toBe("a\nb\n[orbit] lines may be missing\ny\nz\n");
    });

    it('keeps following after one failed poll', function (): void {
        log_follow_profile();
        log_follow_transport(log_follow_handshake('1.2', log_follow_stream_id('a')));
        $responses = [
            MockResponse::make(['data' => ['id' => 41, 'name' => 'worker', 'lines' => 100, 'logs' => "a\n"], 'meta' => ['request_id' => log_follow_request_id()]]),
            MockResponse::make('<html>502 Bad Gateway</html>', 502),
            MockResponse::make(['data' => ['id' => 41, 'name' => 'worker', 'lines' => 100, 'logs' => "a\nb\n"], 'meta' => ['request_id' => log_follow_request_id()]]),
        ];
        MockClient::global([
            CreateLogStreamRequest::class => log_follow_refusal('ssh_only'),
            ProcessLogsRequest::class => function () use (&$responses): MockResponse {
                return count($responses) > 1 ? array_shift($responses) : $responses[0];
            },
        ]);
        log_follow_interrupt_after($this->clock, 4);

        expect(Artisan::call('process:logs', ['process' => '41', '--follow' => true]))->toBe(0)
            ->and(Artisan::output())->toBe("Following over SSH; polling every 5 seconds.\na\nb\n");
    });

    it('keeps the stream after a renewal fails with a 502', function (): void {
        log_follow_profile();
        $transport = log_follow_transport(log_follow_handshake('1.2', log_follow_stream_id('a')));
        $renewals = 0;
        $mock = MockClient::global([
            ...log_follow_stream_mocks('a'),
            RenewLogStreamRequest::class => function () use (&$renewals, $transport): MockResponse {
                if (++$renewals === 2) {
                    return MockResponse::make('<html>502 Bad Gateway</html>', 502);
                }

                if ($renewals === 3) {
                    $transport->enqueue([log_follow_lines('a', 1, ['still live'])]);
                }

                return log_follow_renewed('a');
            },
        ]);
        $start = $this->clock->now();
        $this->clock->onTick(function (float $now) use ($start): void {
            if ($now - $start >= 41.0) {
                InterruptIntent::record(SIGINT);
            }
        });

        expect(Artisan::call('process:logs', ['process' => '41', '--follow' => true]))->toBe(0)
            ->and(Artisan::output())->toBe("still live\n")
            ->and($renewals)->toBe(3)
            ->and(log_follow_sent($mock, CreateLogStreamRequest::class))->toHaveCount(1);
    });

    it('closes the stream when the follow fails unexpectedly', function (): void {
        log_follow_profile();
        log_follow_transport([...log_follow_handshake('1.2', log_follow_stream_id('a')), null, new RuntimeException('boom')]);
        $mock = MockClient::global(log_follow_stream_mocks('a'));

        expect(fn () => Artisan::call('process:logs', ['process' => '41', '--follow' => true]))->toThrow(RuntimeException::class, 'boom')
            ->and(log_follow_sent($mock, DestroyLogStreamRequest::class))->toHaveCount(1);
    });

    it('redacts credential-shaped live lines before it prints them', function (): void {
        log_follow_profile();
        log_follow_transport([
            ...log_follow_handshake('1.2', log_follow_stream_id('a')),
            log_follow_lines('a', 1, ['API_KEY=live-follow-sentinel', 'Authorization: Bearer live-follow-bearer-sentinel']),
        ]);
        MockClient::global(log_follow_stream_mocks('a'));
        log_follow_interrupt_after($this->clock, 1);

        Artisan::call('process:logs', ['process' => '41', '--follow' => true]);

        expect(Artisan::output())->toBe("API_KEY=[redacted]\nAuthorization: [redacted]\n");
    });
});

describe('instance:logs --follow', function (): void {
    it('opens the stream through the Instance and polls the Instance read on fallback', function (): void {
        log_follow_profile();
        log_follow_transport([
            ...log_follow_handshake('1.2', log_follow_stream_id('a')),
            log_follow_lines('a', 1, ['[2026-09-25 10:15:02] local.ERROR: boom']),
            log_follow_ended('a', 'source_unavailable'),
        ]);
        $mock = MockClient::global([
            ...log_follow_stream_mocks('a'),
            InstanceLogsRequest::class => log_follow_reads(["[2026-09-25 10:15:02] local.ERROR: boom\nnext\n"], name: 'shop'),
        ]);
        log_follow_interrupt_after($this->clock, 2);

        $exitCode = Artisan::call('instance:logs', ['instance' => '12', '--follow' => true, '--lines' => '50']);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toBe(
                "[2026-09-25 10:15:02] local.ERROR: boom\nLive tail unavailable (source_unavailable); polling every 5 seconds.\nnext\n",
            )
            ->and(log_follow_sent($mock, CreateLogStreamRequest::class)[0]->getUrl())
            ->toBe('https://10.44.0.1/api/v1/instances/12/log-streams')
            ->and(log_follow_sent($mock, CreateLogStreamRequest::class)[0]->body()?->all())
            ->toBe(['socket_id' => '1.2', 'lines' => 50])
            ->and(log_follow_sent($mock, InstanceLogsRequest::class)[0]->getUrl())
            ->toBe('https://10.44.0.1/api/v1/instances/12/logs');
    });

    it('follows a production Instance log over SSH without calling it unavailable', function (): void {
        log_follow_profile();
        log_follow_transport(log_follow_handshake('1.2', log_follow_stream_id('a')));
        MockClient::global([
            CreateLogStreamRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'logs.live_unavailable',
                    'message' => 'A production Instance log is read over SSH only.',
                    'details' => ['reason' => 'ssh_only'],
                ],
            ], 409),
            InstanceLogsRequest::class => log_follow_reads(["a\nb\n"], name: 'shop'),
        ]);
        log_follow_interrupt_after($this->clock, 2);

        expect(Artisan::call('instance:logs', ['instance' => '12', '--follow' => true]))->toBe(0)
            ->and(Artisan::output())->toBe("Following over SSH; polling every 5 seconds.\na\nb\n");
    });
});

function log_follow_profile(): void
{
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        realtimeUrl: 'wss://reverb.test',
        realtimeKey: 'app-key',
    ));
}

/** @param  list<array<string, mixed>|null>  $messages */
function log_follow_transport(array $messages): FakeWebSocketTransport
{
    $transport = new FakeWebSocketTransport($messages);
    app()->instance(WebSocketTransport::class, $transport);

    return $transport;
}

function log_follow_stream_id(string $seed): string
{
    return str_repeat($seed, 32);
}

function log_follow_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

/** @return list<array<string, mixed>> */
function log_follow_handshake(string $socketId, string $streamId): array
{
    return [
        ['event' => 'pusher:connection_established', 'data' => json_encode(['socket_id' => $socketId, 'activity_timeout' => 30])],
        ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-log-stream.'.$streamId, 'data' => '{}'],
    ];
}

/**
 * @param  list<string>  $lines
 * @return array<string, mixed>
 */
function log_follow_lines(string $stream, int $sequence, array $lines, int $dropped = 0, int $skipped = 0, ?string $channelStream = null): array
{
    return [
        'event' => 'log.lines',
        'channel' => 'private-log-stream.'.log_follow_stream_id($channelStream ?? $stream),
        'data' => json_encode([
            'type' => 'log.lines',
            'id' => log_follow_stream_id($stream),
            'at' => '2026-09-25T10:15:02+00:00',
            'data' => ['sequence' => $sequence, 'lines' => $lines, 'dropped' => $dropped, 'skipped' => $skipped],
        ]),
    ];
}

/** @return array<string, mixed> */
function log_follow_ended(string $stream, string $reason): array
{
    return [
        'event' => 'log.ended',
        'channel' => 'private-log-stream.'.log_follow_stream_id($stream),
        'data' => json_encode([
            'type' => 'log.ended',
            'id' => log_follow_stream_id($stream),
            'at' => '2026-09-25T10:15:02+00:00',
            'data' => ['reason' => $reason],
        ]),
    ];
}

/** @return array<string, mixed> */
function log_follow_stream_envelope(string $stream): array
{
    return [
        'data' => [
            'id' => log_follow_stream_id($stream),
            'channel' => 'private-log-stream.'.log_follow_stream_id($stream),
            'auth' => 'app-key:log-signature',
            'lines' => 100,
            'lease_seconds' => 60,
            'renew_seconds' => 20,
        ],
        'meta' => ['request_id' => log_follow_request_id()],
    ];
}

function log_follow_renewed(string $stream): MockResponse
{
    return MockResponse::make([
        'data' => ['id' => log_follow_stream_id($stream), 'lease_seconds' => 60],
        'meta' => ['request_id' => log_follow_request_id()],
    ]);
}

/** @return array<class-string, MockResponse> */
function log_follow_stream_mocks(string $stream): array
{
    return [
        CreateLogStreamRequest::class => MockResponse::make(log_follow_stream_envelope($stream), 201),
        RenewLogStreamRequest::class => log_follow_renewed($stream),
        DestroyLogStreamRequest::class => MockResponse::make([
            'data' => ['id' => log_follow_stream_id($stream), 'closed' => true],
            'meta' => ['request_id' => log_follow_request_id()],
        ]),
    ];
}

/**
 * One one-shot read response per call, repeating the last one.
 *
 * @param  list<string>  $reads
 */
function log_follow_reads(array $reads, string $name = 'worker'): Closure
{
    return function () use (&$reads, $name): MockResponse {
        $logs = count($reads) > 1 ? array_shift($reads) : $reads[0];

        return MockResponse::make([
            'data' => ['id' => 41, 'name' => $name, 'lines' => 100, 'logs' => $logs],
            'meta' => ['request_id' => log_follow_request_id()],
        ]);
    };
}

function log_follow_refusal(string $reason): MockResponse
{
    return MockResponse::make([
        'error' => ['code' => 'logs.live_unavailable', 'message' => 'Live logs are not available for this Node.', 'details' => ['reason' => $reason]],
    ], 409);
}

/**
 * A log file that the one-shot read tails: each read returns its last `lines` lines as they are then.
 *
 * @param  list<string>  $file
 */
function log_follow_file(array &$file): Closure
{
    return function (PendingRequest $request) use (&$file): MockResponse {
        $lines = (int) $request->query()->get('lines');
        $tail = array_slice($file, -$lines);

        return MockResponse::make([
            'data' => ['id' => 41, 'name' => 'worker', 'lines' => $lines, 'logs' => $tail === [] ? '' : implode("\n", $tail)."\n"],
            'meta' => ['request_id' => log_follow_request_id()],
        ]);
    };
}

function log_follow_interrupt_after(FakeLogFollowClock $clock, int $sleeps): void
{
    $clock->onTick(function (float $now, int $count) use ($sleeps): void {
        if ($count >= $sleeps) {
            InterruptIntent::record(SIGINT);
        }
    });
}

/**
 * @param  class-string  $class
 * @return list<PendingRequest>
 */
function log_follow_sent(MockClient $mock, string $class): array
{
    $sent = [];

    foreach ($mock->getRecordedResponses() as $response) {
        if ($response->getRequest() instanceof $class) {
            $sent[] = $response->getPendingRequest();
        }
    }

    return $sent;
}

/** @return list<array<string, mixed>> */
function log_follow_json_lines(string $output): array
{
    return array_map(
        static fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", trim($output)))),
    );
}
