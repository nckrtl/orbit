<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Deployments\DeployAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\ListAppInstanceReleasesRequest;
use Orbit\Sdk\Requests\Deployments\RollbackAppInstanceRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/InstanceSourceOutput.php';

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=400');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-deployments-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('retained releases', function (): void {
    it('lists the current retained releases through one typed SDK request', function (): void {
        $mock = MockClient::global([
            ListAppInstanceReleasesRequest::class => deployment_cli_releases_response(),
        ]);

        expect(Artisan::call('instance:release:list', ['instance' => '17']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'RELEASE SELECTED', 'release-a no', 'release-b yes',
            'Selected release: release-b', 'Request ID: '.deployment_cli_request_id(),
        );

        expect($mock->getLastRequest())
            ->toBeInstanceOf(ListAppInstanceReleasesRequest::class)
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    });

    it('renders the exact retained-release JSON response', function (): void {
        MockClient::global([
            ListAppInstanceReleasesRequest::class => deployment_cli_releases_response(),
        ]);

        $this
            ->artisan('instance:release:list', ['instance' => '17', '--json' => true])
            ->expectsOutput(json_encode([
                'releases' => ['release-a', 'release-b'],
                'selected_release' => 'release-b',
                'request_id' => deployment_cli_request_id(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->assertExitCode(0);
    });
});

describe('deployment streams', function (): void {
    it('renders a progress tree with incremental application bytes safely for a human', function (): void {
        $bytes = "first\x1b[31m\xff\n";
        $mock = MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'source_preparation'),
                deployment_cli_phase(2, 'environment_sync'),
                deployment_cli_phase(3, 'before_activation', 'migrate'),
                deployment_cli_output(4, 'stdout', $bytes),
                deployment_cli_output(5, 'stderr', "warning\r\n"),
                deployment_cli_phase(6, 'activation'),
                deployment_cli_result(7, 'succeeded', selectedRelease: 'release-b'),
            ]),
        ]);

        $exitCode = Artisan::call('instance:deploy', [
            'instance' => '17',
            '--no-interaction' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)
            ->toContain(
                'Deploy AppInstance [17]',
                '● Resolved release',
                '● Synced environment',
                '● Ran migrate',
                'stdout: "first\\u001b[31m\\ufffd\\n"',
                'stderr: "warning\\r\\n"',
                '● Activated release',
                'Deployment succeeded.',
                'Selected release: release-b',
                'Request ID: '.deployment_cli_request_id(),
            )
            ->not->toContain("\x1b[31m")
            ->and($output)->not->toContain("\xff")
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1)
            ->and($mock->getLastResponse()?->stream()->isReadable())
            ->toBeFalse();
    });

    it('does not repeat the tree frame while several output lines print during one step', function (): void {
        $mock = MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'source_preparation'),
                deployment_cli_phase(2, 'environment_sync'),
                deployment_cli_phase(3, 'before_activation', 'slow'),
                deployment_cli_output(4, 'stdout', "line one\n"),
                deployment_cli_output(5, 'stdout', "line two\n"),
                deployment_cli_output(6, 'stdout', "line three\n"),
                deployment_cli_phase(7, 'activation'),
                deployment_cli_result(8, 'succeeded', selectedRelease: 'release-b'),
            ]),
        ]);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)
            ->toContain('stdout: "line one\\n"', 'stdout: "line two\\n"', 'stdout: "line three\\n"')
            ->and(substr_count($output, 'Deploy AppInstance [17]'))->toBe(1)
            ->and(substr_count($output, 'Deployment succeeded.'))->toBe(1)
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    });

    it('emits only exact compact NDJSON events in JSON mode', function (): void {
        $events = [
            deployment_cli_phase(1, 'source_preparation'),
            deployment_cli_output(2, 'stdout', "first\0bytes\n"),
            deployment_cli_result(3, 'succeeded', selectedRelease: 'release-b'),
        ];
        MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response($events),
        ]);

        $exitCode = Artisan::call('instance:deploy', [
            'instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);
        $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

        expect($exitCode)->toBe(0)
            ->and($lines)->toHaveCount(3)
            ->and(array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                $lines,
            ))->toBe($events)
            ->and(Artisan::output())->not->toContain('Phase:')
            ->and(Artisan::output())->not->toContain('Result:')
            ->and(Artisan::output())->not->toContain('Request ID:')
            ->and(Artisan::output())->not->toContain('?');
    });

    it('renders a rollback progress tree for a human', function (): void {
        $mock = MockClient::global([
            RollbackAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'rollback'),
                deployment_cli_result(2, 'succeeded', selectedRelease: 'release-a'),
            ]),
        ]);

        $exitCode = Artisan::call('instance:rollback', [
            'instance' => '17',
            '--release' => 'release-a',
            '--no-interaction' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)
            ->toContain(
                'Roll back AppInstance [17]',
                '● Selected release',
                'Rollback succeeded.',
                'Selected release: release-a',
                'Request ID: '.deployment_cli_request_id(),
            );

        expect($mock->getRecordedResponses())->toHaveCount(1);
    });

    it('marks the current row failed for a rollback activation failure (F2a)', function (): void {
        // rollback's only admitted row is 'rollback' ('Select release'); activation has no
        // row of its own, so the reached row must take the failure instead of looking merely
        // skipped. Footer color is covered generically in ProgressDisplayTest.php.
        MockClient::global([
            RollbackAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'rollback'),
                deployment_cli_result(2, 'failed', failedStep: 'activation', errorCode: 'deployment.activation_failed'),
            ]),
        ]);

        $exitCode = Artisan::call('instance:rollback', [
            'instance' => '17',
            '--release' => 'release-a',
            '--no-interaction' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)
            ->toContain(
                '● Selecting release',
                'deployment.activation_failed',
                'Rollback failed.',
                'Failed boundary: activation',
                'Error code: deployment.activation_failed',
            )
            ->not->toContain('Selected release:');
    });

    it('marks the current row failed for a rollback cache_refresh failure', function (): void {
        MockClient::global([
            RollbackAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'rollback'),
                deployment_cli_result(2, 'failed', failedStep: 'cache_refresh', errorCode: 'deployment.cache_refresh_failed'),
            ]),
        ]);

        $exitCode = Artisan::call('instance:rollback', [
            'instance' => '17',
            '--release' => 'release-a',
            '--no-interaction' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)
            ->toContain(
                '● Selecting release',
                'deployment.cache_refresh_failed',
                'Rollback failed.',
                'Failed boundary: cache_refresh',
                'Error code: deployment.cache_refresh_failed',
            )
            ->not->toContain('Selected release:');
    });

    it('uses one typed rollback request with only the selected release', function (): void {
        $mock = MockClient::global([
            RollbackAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'rollback'),
                deployment_cli_result(2, 'succeeded', selectedRelease: 'release-a'),
            ]),
        ]);

        $this
            ->artisan('instance:rollback', [
                'instance' => '17',
                '--release' => 'release-a',
                '--json' => true,
            ])
            ->assertExitCode(0);

        expect($mock->getLastRequest())
            ->toBeInstanceOf(RollbackAppInstanceRequest::class)
            ->and($mock->getLastRequest()?->body()->all())
            ->toBe(['release' => 'release-a'])
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    });

    it('rejects a missing rollback release before sending a request', function (): void {
        $mock = MockClient::global();

        $exitCode = Artisan::call('instance:rollback', [
            'instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(deployment_cli_error(
                'deployment.release_required',
                'A retained release name is required.',
            ))
            ->and($mock->getLastPendingRequest())
            ->toBeNull();
    });

    it('emits a failed result event in JSON mode without rewriting it as an error envelope', function (): void {
        $event = deployment_cli_result(
            1,
            'failed',
            failedStep: 'operation',
            errorCode: 'deployment_config.unavailable',
        );
        MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([$event]),
        ]);

        $exitCode = Artisan::call('instance:deploy', [
            'instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe($event)
            ->and($payload)->not->toHaveKey('error');
    });

    it('shows a failed named deploy step with its name and error in the progress tree', function (): void {
        $mock = MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'source_preparation'),
                deployment_cli_phase(2, 'environment_sync'),
                deployment_cli_phase(3, 'activation'),
                deployment_cli_phase(4, 'after_activation', 'notify'),
                deployment_cli_result(
                    5,
                    'failed',
                    failedStep: 'after_activation',
                    errorCode: 'deployment.step_failed',
                    selectedRelease: 'release-b',
                ),
            ]),
        ]);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)
            ->toContain(
                '● Resolved release',
                '● Synced environment',
                '● Activated release',
                '● Running notify',
                'deployment.step_failed',
                'Deployment failed.',
                'Failed boundary: after_activation',
                'Error code: deployment.step_failed',
                'Selected release: release-b',
                'Request ID: '.deployment_cli_request_id(),
            );

        expect($mock->getRecordedResponses())->toHaveCount(1);
    });

    it('shows a before-activation step failure and skips the unreached activation row', function (): void {
        $mock = MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'source_preparation'),
                deployment_cli_phase(2, 'environment_sync'),
                deployment_cli_phase(3, 'before_activation', 'migrate'),
                deployment_cli_result(4, 'failed', failedStep: 'before_activation', errorCode: 'deployment.step_failed'),
            ]),
        ]);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)
            ->toContain(
                '● Resolved release',
                '● Synced environment',
                '● Running migrate',
                'deployment.step_failed',
                '● Activate release',
                'Not reached.',
                'Deployment failed.',
                'Failed boundary: before_activation',
                'Error code: deployment.step_failed',
            )
            ->not->toContain('Selected release:');

        expect($mock->getRecordedResponses())->toHaveCount(1);
    });

    it('marks the current row failed for an operation-level failure before any deploy phase starts', function (): void {
        $mock = MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_result(1, 'failed', failedStep: 'operation', errorCode: 'deployment_config.unavailable'),
            ]),
        ]);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);
        $output = Artisan::output();

        // The "operation" boundary has no row of its own (F2): the row that was current when
        // the stream ended (the opening phase, never even started here) takes the failure
        // instead of every row merely looking skipped.
        expect($exitCode)->toBe(1)
            ->and($output)
            ->toContain(
                'Deploy AppInstance [17]',
                '● Resolving release',
                'deployment_config.unavailable',
                '● Sync environment',
                '● Activate release',
                'Not reached.',
                'Deployment failed.',
                'Failed boundary: operation',
                'Error code: deployment_config.unavailable',
                'Request ID: '.deployment_cli_request_id(),
            )
            ->not->toContain('Selected release:')
            ->and($output)->not->toContain('Resolved release')
            ->and($output)->not->toContain('Synced environment')
            ->and($output)->not->toContain('Activated release');

        expect($mock->getRecordedResponses())->toHaveCount(1);
    });

    it('renders a wrong opening phase as a best-effort tree instead of rejecting it, in human and JSON alike (F6)', function (string $mode): void {
        // main's JSON contract accepts this SDK-valid stream, and human must not be stricter
        // than JSON: neither mode rejects it. Human renders it best-effort (the opening row
        // still stands in for whatever phase actually arrived first).
        MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'environment_sync'),
                deployment_cli_result(2, 'succeeded', selectedRelease: 'release-a'),
            ]),
        ]);

        $exitCode = Artisan::call('instance:deploy', array_filter([
            'instance' => '17',
            '--no-interaction' => true,
            '--json' => $mode === '--json' ?: null,
        ]));
        $output = Artisan::output();

        expect($exitCode)->toBe(0)->and($output)->not->toContain('deployment.stream_invalid', 'stream is invalid');
    })->with(['human' => 'human', 'JSON' => '--json']);

    it('renders an out-of-order phase as a best-effort tree instead of rejecting it (F6)', function (): void {
        MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'source_preparation'),
                deployment_cli_phase(2, 'activation'),
                deployment_cli_phase(3, 'environment_sync'),
                deployment_cli_result(4, 'succeeded', selectedRelease: 'release-a'),
            ]),
        ]);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);
        $output = Artisan::output();

        // 'environment_sync' (order 1) arriving after 'activation' (order 2) is out of order,
        // but not a literal duplicate, so it renders as the next step rather than refusing.
        expect($exitCode)->toBe(0)
            ->and($output)
            ->toContain('● Resolved release', '● Activated release', '● Synced environment', 'Deployment succeeded.')
            ->not->toContain('deployment.stream_invalid', 'stream is invalid');
    });

    it('settles the tree for a literal duplicate phase instead of an uncaught LogicException (F6)', function (): void {
        MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'source_preparation'),
                deployment_cli_phase(2, 'source_preparation'),
                deployment_cli_result(3, 'succeeded', selectedRelease: 'release-a'),
            ]),
        ]);

        // JSON never rejects (main's contract); only human mode's ProgressDisplay guards a
        // literal duplicate, since the repeated step cannot be re-admitted or re-started.
        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)
            ->toContain('Gateway deployment stream is invalid.', 'Deployment failed.')
            ->not->toContain('LogicException', 'Selected release:');
    });

    it('does not lose the request ID for an output-first stream (F6)', function (): void {
        MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_output(1, 'stdout', 'too early'),
                deployment_cli_phase(2, 'source_preparation'),
                deployment_cli_result(3, 'succeeded', selectedRelease: 'release-a'),
            ]),
        ]);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)->and($output)->toContain(
            'stdout: "too early"', 'Deployment succeeded.', 'Request ID: '.deployment_cli_request_id(),
        );
    });

    it('guards finish() for a succeeded result that never reached activation (F6)', function (): void {
        MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'source_preparation'),
                deployment_cli_result(2, 'succeeded', selectedRelease: 'release-a'),
            ]),
        ]);

        // The Gateway cannot send this today (a succeeded result before every always-emitted
        // phase fired), but finish() must not throw an uncaught LogicException over it: the
        // never-started rows settle as skipped, like any other unreached row, not success.
        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)
            ->toContain('● Resolved release', 'Sync environment', 'Activate release', 'Not reached.', 'Deployment succeeded.')
            ->not->toContain('LogicException');
    });

    it('rejects a truncated stream with a correlated safe error and no resubmission', function (): void {
        $mock = MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'source_preparation'),
            ]),
        ]);

        $exitCode = Artisan::call('instance:deploy', [
            'instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);
        $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

        expect($exitCode)->toBe(1)
            ->and($lines)->toHaveCount(2)
            ->and(json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR))
            ->toBe(deployment_cli_phase(1, 'source_preparation'))
            ->and($lines[1])
            ->toBe(deployment_cli_error(
                'deployment.stream_invalid',
                'Gateway deployment stream is invalid.',
                deployment_cli_request_id(),
            ))
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1)
            ->and($mock->getLastResponse()?->stream()->isReadable())
            ->toBeFalse();
    });

    it('preserves shared safe pre-admission errors and request IDs', function (): void {
        $mock = MockClient::global([
            DeployAppInstanceRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'deployment.busy',
                    'message' => "Deployment\0is busy.",
                    'details' => ['command' => 'secret-deploy-command'],
                ],
            ], 409, ['X-Orbit-Request-Id' => deployment_cli_request_id()]),
        ]);

        $exitCode = Artisan::call('instance:deploy', [
            'instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(deployment_cli_error(
                'deployment.busy',
                'Deployment is busy.',
                deployment_cli_request_id(),
            ))
            ->not->toContain('secret-deploy-command')
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    });

    it('leaves SIGINT at its default for stream and non-stream commands', function (): void {
        $commands = Artisan::all();

        expect($commands['instance:deploy']->getSubscribedSignals())->toBe([])
            ->and($commands['instance:rollback']->getSubscribedSignals())->toBe([])
            ->and($commands['instance:release:list']->getSubscribedSignals())->toBe([]);
    });

    it('does not register the replaced release-list name', function (): void {
        expect(Artisan::all())->not->toHaveKeys(['instance:releases']);
    });

    it('interrupts a synchronous Gateway request before response headers arrive', function (int $signal, string $mode): void {
        if (! function_exists('pcntl_signal_dispatch') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX signals are unavailable.');
        }

        $fixture = deployment_cli_signal_fixture($this->orbitHome, 'headers-late');
        $command = new Process(
            [PHP_BINARY, dirname(__DIR__, 3).'/orbit', 'node:role:add', '17', 'app-prod', '--converge', $mode],
            env: ['ORBIT_HOME' => $fixture['home']],
            timeout: 10,
        );

        try {
            $command->start();
            deployment_cli_wait_until(static fn (): bool => is_file($fixture['request']));
            expect($command->isRunning())->toBeTrue();
            $startedAt = microtime(true);
            $command->signal($signal);
            $exitCode = $command->wait();
            $elapsed = microtime(true) - $startedAt;
            expect($exitCode)->toBe(128 + $signal)
                ->and($elapsed)->toBeLessThan(2.0);
            deployment_cli_wait_until(static fn (): bool => is_file($fixture['disconnected']));

            expect($fixture['server']->wait())->toBe(0)
                ->and($command->getErrorOutput())->toBe('')
                ->and($command->getOutput())->not->toContain('Role [app-prod] added')
                ->and($command->getOutput())->not->toContain('Added Node role.');

            if ($mode === '--json') {
                expect($command->getOutput())->toBe('');
            } else {
                expect($command->getOutput())->toContain('Operation interrupted.');
            }
        } finally {
            if ($command->isRunning()) {
                $command->stop(0.1, 9);
            }

            if ($fixture['server']->isRunning()) {
                $fixture['server']->stop(0.1, 9);
            }
        }
    })->with([SIGINT, SIGTERM])->with(['--ansi', '--no-ansi', '--json']);

    it('terminates promptly and disconnects before headers arrive', function (): void {
        if (! defined('SIGINT') || ! function_exists('posix_kill') || ! function_exists('openssl_csr_new')) {
            $this->markTestSkipped('POSIX signals are unavailable.');
        }

        $fixture = deployment_cli_signal_fixture($this->orbitHome, 'headers-late');
        $output = '';
        $command = new Process(
            [PHP_BINARY, dirname(__DIR__, 3).'/orbit', 'instance:deploy', '17', '--json', '--no-interaction'],
            env: ['ORBIT_HOME' => $fixture['home']],
            timeout: 10,
        );

        try {
            $command->start(static function (string $type, string $data) use (&$output): void {
                $output .= $data;
            });
            deployment_cli_wait_until(static fn (): bool => is_file($fixture['request']));

            if (! $command->isRunning()) {
                throw new RuntimeException(
                    "Deployment command exited before SIGINT: {$output}\n"
                    .'Request: '.file_get_contents($fixture['request'])."\n"
                    .'Server stdout: '.$fixture['server']->getOutput()."\n"
                    .'Server stderr: '.$fixture['server']->getErrorOutput(),
                );
            }
            $startedAt = microtime(true);
            $command->signal(SIGINT);
            $exitCode = $command->wait();
            $elapsed = microtime(true) - $startedAt;
            deployment_cli_wait_until(static fn (): bool => is_file($fixture['disconnected']));
            $serverExitCode = $fixture['server']->wait();

            expect($exitCode)->not->toBe(0)
                ->and($command->getTermSignal())->toBe(SIGINT)
                ->and($elapsed)->toBeLessThan(2.0)
                ->and($serverExitCode)->toBe(0)
                ->and($output)->not->toContain('"type":"result"')
                ->and($output)->not->toContain('succeeded');
        } finally {
            if ($command->isRunning()) {
                $command->stop(0.1, 9);
            }

            if ($fixture['server']->isRunning()) {
                $fixture['server']->stop(0.1, 9);
            }
        }
    });

    it('interrupts a blocked stream read within one second, decorated, plain, or JSON', function (string $mode): void {
        if (! defined('SIGINT') || ! function_exists('posix_kill') || ! function_exists('openssl_csr_new')) {
            $this->markTestSkipped('POSIX signals are unavailable.');
        }

        if ($mode === '--ansi' && ! Process::isPtySupported()) {
            $this->markTestSkipped('PTY is unavailable.');
        }

        $fixture = deployment_cli_signal_fixture($this->orbitHome, 'body-blocked');
        $output = '';
        $command = new Process(
            [PHP_BINARY, dirname(__DIR__, 3).'/orbit', 'instance:deploy', '17', $mode, '--no-interaction'],
            env: ['ORBIT_HOME' => $fixture['home']],
            timeout: 10,
        );

        if ($mode === '--ansi') {
            $command->setPty(true);
        }

        try {
            $command->start(static function (string $type, string $data) use (&$output): void {
                $output .= $data;
            });
            deployment_cli_wait_until(static fn (): bool => is_file($fixture['stream']));
            usleep(100_000);

            if (! $command->isRunning()) {
                throw new RuntimeException(
                    "Deployment command exited before SIGINT: {$output}\n"
                    .'Request: '.file_get_contents($fixture['request'])."\n"
                    .'Server stdout: '.$fixture['server']->getOutput()."\n"
                    .'Server stderr: '.$fixture['server']->getErrorOutput(),
                );
            }
            $startedAt = microtime(true);
            $command->signal(SIGINT);
            $exitCode = $command->wait();
            $elapsed = microtime(true) - $startedAt;
            deployment_cli_wait_until(static fn (): bool => is_file($fixture['disconnected']));
            $serverExitCode = $fixture['server']->wait();

            expect($exitCode)->toBe(130)
                ->and($elapsed)->toBeLessThan(1.0)
                ->and($serverExitCode)->toBe(0)
                ->and($output)->not->toContain('"type":"result"')
                ->and($output)->not->toContain('succeeded')
                ->and($output)->not->toContain('Deployment stream failed.');

            if ($mode === '--ansi') {
                expect($output)->toContain("\e[?25h");
            }
        } finally {
            if ($command->isRunning()) {
                $command->stop(0.1, 9);
            }

            if ($fixture['server']->isRunning()) {
                $fixture['server']->stop(0.1, 9);
            }
        }
    })->with(['decorated (PTY)' => '--ansi', 'plain' => '--no-ansi', 'JSON' => '--json']);
});

function deployment_cli_releases_response(): MockResponse
{
    return MockResponse::make([
        'data' => [
            'releases' => ['release-a', 'release-b'],
            'selected_release' => 'release-b',
        ],
        'meta' => ['request_id' => deployment_cli_request_id()],
    ]);
}

/** @param list<array<string, mixed>> $events */
function deployment_cli_stream_response(array $events): MockResponse
{
    return MockResponse::make(
        implode('', array_map(
            static fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n",
            $events,
        )),
        headers: deployment_cli_stream_headers(),
    );
}

/** @return array<string, mixed> */
function deployment_cli_phase(int $sequence, string $phase, ?string $stepName = null): array
{
    $event = [
        'type' => 'phase',
        'sequence' => $sequence,
        'request_id' => deployment_cli_request_id(),
        'phase' => $phase,
    ];

    if ($stepName !== null) {
        $event['step_name'] = $stepName;
    }

    return $event;
}

/** @return array<string, mixed> */
function deployment_cli_output(int $sequence, string $stream, string $data): array
{
    return [
        'type' => 'output',
        'sequence' => $sequence,
        'request_id' => deployment_cli_request_id(),
        'stream' => $stream,
        'data_base64' => base64_encode($data),
    ];
}

/** @return array<string, mixed> */
function deployment_cli_result(
    int $sequence,
    string $status,
    ?string $failedStep = null,
    ?string $errorCode = null,
    ?string $selectedRelease = null,
): array {
    return [
        'type' => 'result',
        'sequence' => $sequence,
        'request_id' => deployment_cli_request_id(),
        'status' => $status,
        'failed_step' => $failedStep,
        'error_code' => $errorCode,
        'selected_release' => $selectedRelease,
    ];
}

/** @return array<string, string> */
function deployment_cli_stream_headers(): array
{
    return [
        'Content-Type' => 'application/x-ndjson',
        'X-Orbit-Request-Id' => deployment_cli_request_id(),
    ];
}

function deployment_cli_error(string $code, string $message, ?string $requestId = null): string
{
    return json_encode([
        'error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function deployment_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a847';
}

/**
 * @return array{
 *     home: string,
 *     request: string,
 *     stream: string,
 *     disconnected: string,
 *     server: Process,
 * }
 */
function deployment_cli_signal_fixture(string $root, string $mode): array
{
    $directory = "{$root}/signal-{$mode}";
    mkdir($directory, 0o700, recursive: true);
    [$certificate, $privateKey] = deployment_cli_signal_certificate($directory);
    $ready = "{$directory}/ready";
    $request = "{$directory}/request";
    $stream = "{$directory}/stream";
    $disconnected = "{$directory}/disconnected";
    $server = new Process([
        PHP_BINARY,
        '-r',
        <<<'PHP'
            $context = stream_context_create(['ssl' => [
                'local_cert' => $argv[1],
                'local_pk' => $argv[2],
                'verify_peer' => false,
            ]]);
            $server = stream_socket_server(
                'tls://127.0.0.1:0',
                $errorNumber,
                $errorMessage,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                $context,
            );

            if ($server === false) {
                fwrite(STDERR, $errorMessage);
                exit($errorNumber ?: 1);
            }

            $address = stream_socket_get_name($server, false);

            if (! is_string($address)) {
                exit(2);
            }

            file_put_contents($argv[3], $address);
            $connection = stream_socket_accept($server, 5);

            if ($connection === false) {
                exit(3);
            }

            $request = '';

            while (! str_contains($request, "\r\n\r\n")) {
                $chunk = fread($connection, 8192);

                if (! is_string($chunk) || $chunk === '') {
                    exit(4);
                }

                $request .= $chunk;
            }

            file_put_contents($argv[4], $request);

            if ($argv[7] === 'body-blocked') {
                preg_match('/^X-Orbit-Request-Id:\s*([^\r\n]+)\r?$/mi', $request, $matches);
                $requestId = $matches[1] ?? '';
                $headers = "HTTP/1.1 200 OK\r\n"
                    ."Content-Type: application/x-ndjson\r\n"
                    ."X-Orbit-Request-Id: {$requestId}\r\n"
                    ."Transfer-Encoding: chunked\r\n"
                    ."Connection: close\r\n\r\n";
                fwrite($connection, $headers);
                $events = [
                    [
                        'type' => 'phase',
                        'sequence' => 1,
                        'request_id' => $requestId,
                        'phase' => 'source_preparation',
                    ],
                    [
                        'type' => 'phase',
                        'sequence' => 2,
                        'request_id' => $requestId,
                        'phase' => 'before_activation',
                        'step_name' => 'slow',
                    ],
                    [
                        'type' => 'output',
                        'sequence' => 3,
                        'request_id' => $requestId,
                        'stream' => 'stdout',
                        'data_base64' => base64_encode("FIRST\n"),
                    ],
                ];

                foreach ($events as $event) {
                    $line = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                    fwrite($connection, dechex(strlen($line))."\r\n{$line}\r\n");
                    fflush($connection);
                }

                file_put_contents($argv[5], 'sent');
            }

            stream_set_blocking($connection, false);
            $deadline = microtime(true) + 5;

            while (microtime(true) < $deadline) {
                $read = [$connection];
                $write = null;
                $except = null;

                if (stream_select($read, $write, $except, 0, 100_000) === 1) {
                    $data = fread($connection, 8192);

                    if ($data === '' && feof($connection)) {
                        file_put_contents($argv[6], 'closed');
                        fclose($connection);
                        fclose($server);
                        exit(0);
                    }
                }
            }

            exit(5);
            PHP,
        $certificate,
        $privateKey,
        $ready,
        $request,
        $stream,
        $disconnected,
        $mode,
    ], timeout: 8);
    $server->start();
    deployment_cli_wait_until(static fn (): bool => is_file($ready));
    $address = file_get_contents($ready);

    if (! is_string($address) || $address === '') {
        throw new RuntimeException('Could not resolve the deployment signal fixture address.');
    }

    $home = "{$directory}/home";
    mkdir($home, 0o700);
    new GatewayConfigRepository("{$home}/config.json")->add(new GatewayProfile(
        name: 'signal',
        url: "https://{$address}",
        caPath: $certificate,
    ));

    return compact('home', 'request', 'stream', 'disconnected', 'server');
}

/** @return array{string, string} */
function deployment_cli_signal_certificate(string $directory): array
{
    $configuration = "{$directory}/openssl.cnf";
    file_put_contents($configuration, <<<'OPENSSL'
        [req]
        distinguished_name = subject
        x509_extensions = v3_ca
        prompt = no

        [subject]
        CN = 127.0.0.1

        [v3_ca]
        subjectAltName = IP:127.0.0.1
        basicConstraints = critical, CA:TRUE
        keyUsage = critical, keyCertSign, digitalSignature
        OPENSSL);
    $key = openssl_pkey_new([
        'config' => $configuration,
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    $certificate = openssl_csr_sign(
        openssl_csr_new(
            ['commonName' => '127.0.0.1'],
            $key,
            ['config' => $configuration],
        ),
        null,
        $key,
        1,
        ['config' => $configuration, 'x509_extensions' => 'v3_ca'],
    );
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $privateKeyPem, null, ['config' => $configuration]);
    $certificatePath = "{$directory}/ca.pem";
    $privateKeyPath = "{$directory}/key.pem";
    file_put_contents($certificatePath, $certificatePem);
    file_put_contents($privateKeyPath, $privateKeyPem);
    chmod($certificatePath, 0o600);
    chmod($privateKeyPath, 0o600);

    return [$certificatePath, $privateKeyPath];
}

function deployment_cli_wait_until(Closure $condition, float $timeoutSeconds = 5): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (! $condition()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the deployment signal fixture.');
        }

        usleep(10_000);
    }
}
