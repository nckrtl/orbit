<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Deployments\DeploymentOutputEvent;
use Orbit\Sdk\Responses\Deployments\DeploymentPhaseEvent;
use Orbit\Sdk\Responses\Deployments\DeploymentResultEvent;
use Orbit\Sdk\Responses\Deployments\DeploymentStream;

describe(DeploymentStream::class, function (): void {
    it('yields each line over a real Guzzle chunked streaming transport', function (): void {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

        if ($server === false) {
            throw new RuntimeException($errorMessage, $errorNumber);
        }

        $address = stream_socket_get_name($server, remote: false);

        if (! is_string($address)) {
            fclose($server);

            throw new RuntimeException('Could not resolve the test server address.');
        }

        $processId = pcntl_fork();

        if ($processId === -1) {
            fclose($server);

            throw new RuntimeException('Could not start the test server.');
        }

        if ($processId === 0) {
            deployment_serve_chunked_stream($server);
        }

        fclose($server);
        $stream = null;

        try {
            $response = (new Client(['handler' => new StreamHandler]))->request(
                'POST',
                "http://{$address}/api/v1/instances/17/deploy",
                ['stream' => true, 'timeout' => 5],
            );
            $stream = new DeploymentStream(
                $response->getBody(),
                static fn () => $response->getBody()->close(),
                $response->getHeaderLine('X-Orbit-Request-Id'),
            );
            $iterator = $stream->getIterator();
            $startedAt = microtime(true);

            $iterator->rewind();

            expect($iterator->current())->toBeInstanceOf(DeploymentPhaseEvent::class)
                ->and(microtime(true) - $startedAt)->toBeLessThan(0.75);

            $iterator->next();
            expect($iterator->current())->toBeInstanceOf(DeploymentResultEvent::class);

            $iterator->next();
            expect($iterator->valid())->toBeFalse();
        } finally {
            $stream?->close();
            pcntl_waitpid($processId, $status);
        }

        expect(pcntl_wexitstatus($status))->toBe(0);
    });

    it('yields typed events incrementally across arbitrary chunks', function (): void {
        $requestId = deployment_stream_request_id();
        $output = "binary\0\xffoutput";
        $body = deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => $requestId,
            'phase' => 'before_activation',
            'step_name' => 'migrate',
        ], leadingWhitespace: 3)
            .deployment_stream_line([
                'type' => 'output',
                'sequence' => 2,
                'request_id' => $requestId,
                'stream' => 'stdout',
                'data_base64' => base64_encode($output),
            ])
            .deployment_stream_line(deployment_stream_result(3));
        $closed = false;
        $stream = deployment_chunked_stream($body, [1, 2, 7, 3, 13], $closed);

        $events = iterator_to_array($stream);

        expect($events)->toHaveCount(3)
            ->and($events[0])->toBeInstanceOf(DeploymentPhaseEvent::class)
            ->and($events[0]->phase)->toBe('before_activation')
            ->and($events[0]->stepName)->toBe('migrate')
            ->and($events[1])->toBeInstanceOf(DeploymentOutputEvent::class)
            ->and($events[1]->stream)->toBe('stdout')
            ->and($events[1]->data)->toBe($output)
            ->and($events[2])->toBeInstanceOf(DeploymentResultEvent::class)
            ->and($events[2]->succeeded())->toBeTrue()
            ->and($events[2]->selectedRelease)->toBe('release-a')
            ->and(array_map(static fn ($event): int => $event->sequence, $events))->toBe([1, 2, 3])
            ->and($closed)->toBeTrue();
    });

    it('yields a typed terminal failure without reporting success', function (): void {
        $body = deployment_stream_line([
            ...deployment_stream_result(1),
            'status' => 'failed',
            'failed_step' => 'before_activation',
            'error_code' => 'deployment.step_failed',
            'selected_release' => null,
        ]);
        $closed = false;
        $events = iterator_to_array(deployment_chunked_stream($body, [2], $closed));

        expect($events)->toHaveCount(1)
            ->and($events[0])->toBeInstanceOf(DeploymentResultEvent::class)
            ->and($events[0]->succeeded())->toBeFalse()
            ->and($events[0]->failedStep)->toBe('before_activation')
            ->and($events[0]->errorCode)->toBe('deployment.step_failed')
            ->and($closed)->toBeTrue();
    });

    it('rejects malformed invalid over-limit misordered and truncated streams', function (string $body): void {
        $closed = false;
        $stream = deployment_chunked_stream($body, [5, 1, 8], $closed);

        expect(fn (): array => iterator_to_array($stream))
            ->toThrow(GatewayApiException::class, 'Gateway deployment stream is invalid.')
            ->and($closed)->toBeTrue();
    })->with([
        'malformed JSON' => ["{not-json}\n"],
        'unknown event member' => [deployment_stream_line([
            ...deployment_stream_result(1),
            'unexpected' => true,
        ])],
        'line over 32 KiB' => [str_repeat(' ', 32 * 1024)."{}\n"],
        'sequence does not start at one' => [deployment_stream_line(deployment_stream_result(2))],
        'sequence is not continuous' => [deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'activation',
        ]).deployment_stream_line(deployment_stream_result(3))],
        'request identity mismatch' => [deployment_stream_line([
            ...deployment_stream_result(1),
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67ffff',
        ])],
        'invalid request identity' => [deployment_stream_line([
            ...deployment_stream_result(1),
            'request_id' => 'invalid-request-id',
        ])],
        'named phase without step name' => [deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'before_activation',
        ])],
        'unnamed phase with step name' => [deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'activation',
            'step_name' => 'unexpected',
        ])],
        'unsupported phase' => [deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'database_migration',
        ])],
        'invalid step name' => [deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'after_activation',
            'step_name' => 'Invalid Step',
        ])],
        'unsupported output stream' => [deployment_stream_line([
            'type' => 'output',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'stream' => 'combined',
            'data_base64' => '',
        ])],
        'invalid base64 output' => [deployment_stream_line([
            'type' => 'output',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'stream' => 'stdout',
            'data_base64' => 'not+canonical===',
        ]).deployment_stream_line(deployment_stream_result(2))],
        'decoded output over 16 KiB' => [deployment_stream_line([
            'type' => 'output',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'stream' => 'stdout',
            'data_base64' => base64_encode(str_repeat('x', (16 * 1024) + 1)),
        ]).deployment_stream_line(deployment_stream_result(2))],
        'result before another event' => [deployment_stream_line(deployment_stream_result(1)).deployment_stream_line([
            'type' => 'phase',
            'sequence' => 2,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'activation',
        ])],
        'successful result without release' => [deployment_stream_line([
            ...deployment_stream_result(1),
            'selected_release' => null,
        ])],
        'failed result without failure boundary' => [deployment_stream_line([
            ...deployment_stream_result(1),
            'status' => 'failed',
            'selected_release' => null,
        ])],
        'failed result with invalid error code' => [deployment_stream_line([
            ...deployment_stream_result(1),
            'status' => 'failed',
            'failed_step' => 'operation',
            'error_code' => 'Invalid Error',
            'selected_release' => null,
        ])],
        'truncated line' => [rtrim(deployment_stream_line(deployment_stream_result(1)), "\n")],
        'end before result' => [deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'activation',
        ])],
    ]);

    it('closes the response when the consumer cancels and never replays events', function (): void {
        $body = deployment_stream_line([
            'type' => 'phase',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'phase' => 'activation',
        ]).deployment_stream_line(deployment_stream_result(2));
        $closed = false;
        $stream = deployment_chunked_stream($body, [4], $closed);
        $iterator = $stream->getIterator();

        $iterator->rewind();
        expect($iterator->current())->toBeInstanceOf(DeploymentPhaseEvent::class)
            ->and($closed)->toBeFalse();

        $stream->close();
        expect($closed)->toBeTrue()
            ->and(fn (): array => iterator_to_array($stream))
            ->toThrow(LogicException::class, 'Deployment streams cannot be replayed.');
    });

    it('rejects an empty transport read before terminal EOF', function (): void {
        $inner = Utils::streamFor(deployment_stream_line(deployment_stream_result(1)));
        $body = FnStream::decorate($inner, [
            'eof' => static fn (): bool => false,
        ]);
        $closed = false;
        $stream = new DeploymentStream(
            $body,
            static function () use (&$closed, $body): void {
                $closed = true;
                $body->close();
            },
            deployment_stream_request_id(),
        );

        expect(fn (): array => iterator_to_array($stream))
            ->toThrow(GatewayApiException::class, 'Gateway deployment stream is invalid.')
            ->and($closed)->toBeTrue();
    });

    it('keeps application output out of generic diagnostics', function (): void {
        $sentinel = 'application-output-sentinel-71af';
        $body = deployment_stream_line([
            'type' => 'output',
            'sequence' => 1,
            'request_id' => deployment_stream_request_id(),
            'stream' => 'stderr',
            'data_base64' => base64_encode($sentinel),
        ]).deployment_stream_line(deployment_stream_result(2));
        $closed = false;
        $events = iterator_to_array(deployment_chunked_stream($body, [11], $closed));
        $event = $events[0];

        expect($event)->toBeInstanceOf(DeploymentOutputEvent::class)
            ->and($event->data)->toBe($sentinel)
            ->and(implode("\n", [
                print_r($event, return: true),
                (string) json_encode($event, JSON_THROW_ON_ERROR),
            ]))->not->toContain($sentinel);
    });
});

describe(DeploymentStream::class.' silence handling', function (): void {
    it('continues after several idle polls once real data arrives, resetting the silence clock', function (): void {
        [$address, $pid] = deployment_start_server(function ($connection): void {
            $send = deployment_open_chunked($connection, deployment_stream_request_id());
            usleep(300_000); // several 0.05 s read_timeout polls, well under the 5 s silence limit
            $send(deployment_stream_line(deployment_stream_result(1)));
            deployment_close_chunked($connection);
        });

        try {
            $stream = deployment_connect_stream($address, readTimeout: 0.05, silenceLimitSeconds: 5.0);
            $events = iterator_to_array($stream);

            expect($events)->toHaveCount(1)
                ->and($events[0])->toBeInstanceOf(DeploymentResultEvent::class);
        } finally {
            pcntl_waitpid($pid, $status);
        }
    });

    it('keeps succeeding when repeated idle gaps each stay under the limit but add up past it, because real data resets the clock', function (): void {
        // Each gap (200 ms) leaves a 100 ms margin under the 300 ms limit on its own; two
        // gaps back to back (400 ms) clear the limit by 100 ms if the clock is never reset.
        // That margin has to survive real scheduling jitter, not just the nominal numbers.
        [$address, $pid] = deployment_start_server(function ($connection): void {
            $send = deployment_open_chunked($connection, deployment_stream_request_id());
            usleep(200_000); // one gap under the limit
            $send(deployment_stream_line([
                'type' => 'phase',
                'sequence' => 1,
                'request_id' => deployment_stream_request_id(),
                'phase' => 'source_preparation',
            ]));
            usleep(200_000); // another gap under the limit; the two together clear it
            $send(deployment_stream_line([
                'type' => 'phase',
                'sequence' => 2,
                'request_id' => deployment_stream_request_id(),
                'phase' => 'activation',
            ]));
            usleep(200_000); // a third gap, again under the limit alone
            $send(deployment_stream_line(deployment_stream_result(3)));
            deployment_close_chunked($connection);
        });

        try {
            $stream = deployment_connect_stream($address, readTimeout: 0.05, silenceLimitSeconds: 0.3);
            $events = iterator_to_array($stream);

            expect($events)->toHaveCount(3)
                ->and($events[2])->toBeInstanceOf(DeploymentResultEvent::class)
                ->and($events[2]->succeeded())->toBeTrue();
        } finally {
            pcntl_waitpid($pid, $status);
        }
    });

    it('fails as an invalid stream when the connection closes during silence', function (): void {
        [$address, $pid] = deployment_start_server(function ($connection): void {
            deployment_open_chunked($connection, deployment_stream_request_id());
            usleep(150_000); // a few idle polls, then an abrupt close with no terminating chunk
            fclose($connection);
        });

        try {
            $stream = deployment_connect_stream($address, readTimeout: 0.05, silenceLimitSeconds: 5.0);

            expect(fn (): array => iterator_to_array($stream))
                ->toThrow(GatewayApiException::class, 'Gateway deployment stream is invalid.');
        } finally {
            pcntl_waitpid($pid, $status);
        }
    });

    it('fails like main once cumulative silence exceeds the injected limit, without waiting for the server', function (): void {
        [$address, $pid] = deployment_start_server(function ($connection): void {
            deployment_open_chunked($connection, deployment_stream_request_id());
            sleep(1); // held open well past the client's 0.2 s silence limit; the client gives up first
            fclose($connection);
        });

        try {
            $stream = deployment_connect_stream($address, readTimeout: 0.05, silenceLimitSeconds: 0.2);
            $startedAt = microtime(true);

            expect(fn (): array => iterator_to_array($stream))
                ->toThrow(GatewayApiException::class, 'Gateway deployment stream is invalid.')
                ->and(microtime(true) - $startedAt)->toBeLessThan(0.8);
        } finally {
            pcntl_waitpid($pid, $status);
        }
    });

    it('fails as invalid when trailing bytes follow the terminal result over the wire', function (): void {
        [$address, $pid] = deployment_start_server(function ($connection): void {
            $send = deployment_open_chunked($connection, deployment_stream_request_id());
            $send(deployment_stream_line(deployment_stream_result(1)));
            usleep(200_000); // a later, separate read: not folded into the same buffer as the result line
            $send('unexpected trailing byte');
            deployment_close_chunked($connection);
        });

        try {
            $stream = deployment_connect_stream($address, readTimeout: 0.05, silenceLimitSeconds: 5.0);

            expect(fn (): array => iterator_to_array($stream))
                ->toThrow(GatewayApiException::class, 'Gateway deployment stream is invalid.');
        } finally {
            pcntl_waitpid($pid, $status);
        }
    });

    it('lets onIdle() abort a silent wait by throwing, propagating exactly what it threw', function (): void {
        [$address, $pid] = deployment_start_server(function ($connection): void {
            deployment_open_chunked($connection, deployment_stream_request_id());
            sleep(1); // long enough that onIdle fires and aborts well before this
            fclose($connection);
        });

        try {
            $stream = deployment_connect_stream($address, readTimeout: 0.05, silenceLimitSeconds: 30.0);
            $signal = new class('idle wait aborted') extends RuntimeException {};
            $calls = 0;
            $stream->onIdle(function () use (&$calls, $signal): void {
                $calls++;

                if ($calls >= 2) {
                    throw $signal;
                }
            });
            $caught = null;

            try {
                iterator_to_array($stream);
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            expect($caught)->toBe($signal)
                ->and($calls)->toBeGreaterThanOrEqual(2);
        } finally {
            pcntl_waitpid($pid, $status);
        }
    });

    it('propagates a RuntimeException subclass from a read instead of retrying it as a timeout', function (): void {
        // A caller's own signal handler (e.g. Ctrl-C) can throw its own RuntimeException
        // subclass from inside the blocked read; that must always propagate, never be
        // swallowed as a timeout retry (see readWithTimeoutTolerance()'s doc comment).
        $signal = new class('interrupted') extends RuntimeException {};
        $inner = Utils::streamFor(deployment_stream_line(deployment_stream_result(1)));
        $calls = 0;
        $body = FnStream::decorate($inner, [
            'read' => static function (int $length) use ($inner, &$calls, $signal): string {
                $calls++;

                if ($calls === 1) {
                    throw $signal;
                }

                return $inner->read($length);
            },
        ]);
        $closed = false;
        $stream = new DeploymentStream(
            $body,
            static function () use (&$closed, $body): void {
                $closed = true;
                $body->close();
            },
            deployment_stream_request_id(),
        );
        $caught = null;

        try {
            iterator_to_array($stream);
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        expect($caught)->toBe($signal)
            ->and($calls)->toBe(1)
            ->and($closed)->toBeTrue();
    });
});

/** @param array<string, mixed> $event */
function deployment_stream_line(array $event, int $leadingWhitespace = 0): string
{
    return str_repeat(' ', $leadingWhitespace).json_encode($event, JSON_THROW_ON_ERROR)."\n";
}

/** @return array<string, mixed> */
function deployment_stream_result(int $sequence): array
{
    return [
        'type' => 'result',
        'sequence' => $sequence,
        'request_id' => deployment_stream_request_id(),
        'status' => 'succeeded',
        'failed_step' => null,
        'error_code' => null,
        'selected_release' => 'release-a',
    ];
}

/** @param list<int> $chunkSizes */
function deployment_chunked_stream(string $body, array $chunkSizes, bool &$closed): DeploymentStream
{
    $inner = Utils::streamFor($body);
    $index = 0;
    $chunked = FnStream::decorate($inner, [
        'read' => static function (int $length) use ($inner, $chunkSizes, &$index): string {
            $size = $chunkSizes[$index % count($chunkSizes)];
            $index++;

            return $inner->read(min($length, $size));
        },
    ]);

    return new DeploymentStream(
        $chunked,
        static function () use (&$closed, $chunked): void {
            $closed = true;
            $chunked->close();
        },
        deployment_stream_request_id(),
    );
}

function deployment_stream_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a846';
}

/** @param resource $server */
function deployment_serve_chunked_stream($server): never
{
    $connection = stream_socket_accept($server, 5);

    if ($connection === false) {
        exit(1);
    }

    while (($line = fgets($connection)) !== false && trim($line) !== '') {
    }

    fwrite($connection, implode("\r\n", [
        'HTTP/1.1 200 OK',
        'Content-Type: application/x-ndjson',
        'X-Orbit-Request-Id: '.deployment_stream_request_id(),
        'Transfer-Encoding: chunked',
        '',
        '',
    ]));

    $send = static function (string $data) use ($connection): void {
        fwrite($connection, dechex(strlen($data))."\r\n{$data}\r\n");
        fflush($connection);
    };

    for ($probe = 0; $probe < 25; $probe++) {
        $send(' ');
        usleep(10_000);
    }

    $send(deployment_stream_line([
        'type' => 'phase',
        'sequence' => 1,
        'request_id' => deployment_stream_request_id(),
        'phase' => 'source_preparation',
    ]));
    usleep(1_100_000);
    $send(deployment_stream_line(deployment_stream_result(2)));
    fwrite($connection, "0\r\n\r\n");
    fclose($connection);
    fclose($server);

    exit(0);
}

/**
 * Forks a one-shot chunked-response server driven by $serve, which receives the accepted
 * connection and is responsible for closing it. Real sockets (plain TCP, no TLS) so the
 * silence tests exercise PHP's actual stream_set_timeout()/getMetadata('timed_out') behavior
 * instead of a synthetic double of it.
 *
 * The parent returns only after the child reports that it is running. The silence tests use a
 * read_timeout of 0.05 s, which Guzzle also applies to the response-header phase, so a child
 * that is still being forked when the request arrives would fail the request. Forking a large
 * test process is slow enough on macOS for that to happen.
 *
 * @return array{0: string, 1: int} [server address, forked child pid]
 */
function deployment_start_server(Closure $serve): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

    if ($server === false) {
        throw new RuntimeException($errorMessage, $errorNumber);
    }

    $address = stream_socket_get_name($server, remote: false);

    if (! is_string($address)) {
        fclose($server);

        throw new RuntimeException('Could not resolve the test server address.');
    }

    $readiness = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    if ($readiness === false) {
        fclose($server);

        throw new RuntimeException('Could not create the test server readiness channel.');
    }

    [$ready, $announce] = $readiness;
    $processId = pcntl_fork();

    if ($processId === -1) {
        fclose($server);
        fclose($ready);
        fclose($announce);

        throw new RuntimeException('Could not start the test server.');
    }

    if ($processId === 0) {
        fclose($ready);
        fwrite($announce, 'r');
        fclose($announce);
        $connection = stream_socket_accept($server, 5);

        if ($connection === false) {
            exit(1);
        }

        $serve($connection);
        fclose($server);

        exit(0);
    }

    fclose($server);
    fclose($announce);
    stream_set_timeout($ready, 5);
    $signal = fread($ready, 1);
    fclose($ready);

    if ($signal !== 'r') {
        posix_kill($processId, SIGKILL);
        pcntl_waitpid($processId, $status);

        throw new RuntimeException('The test server did not start.');
    }

    return [$address, $processId];
}

/** Reads the request up to its blank line and writes chunked-response headers. @return Closure(string): void */
function deployment_open_chunked(mixed $connection, string $requestId): Closure
{
    while (($line = fgets($connection)) !== false && trim($line) !== '') {
    }

    fwrite($connection, implode("\r\n", [
        'HTTP/1.1 200 OK',
        'Content-Type: application/x-ndjson',
        'X-Orbit-Request-Id: '.$requestId,
        'Transfer-Encoding: chunked',
        '',
        '',
    ]));

    return static function (string $data) use ($connection): void {
        fwrite($connection, dechex(strlen($data))."\r\n{$data}\r\n");
        fflush($connection);
    };
}

function deployment_close_chunked(mixed $connection): void
{
    fwrite($connection, "0\r\n\r\n");
    fclose($connection);
}

function deployment_connect_stream(string $address, float $readTimeout, float $silenceLimitSeconds): DeploymentStream
{
    $response = (new Client(['handler' => new StreamHandler]))->request(
        'POST',
        "http://{$address}/api/v1/instances/17/deploy",
        ['stream' => true, 'timeout' => 30, 'read_timeout' => $readTimeout],
    );

    return new DeploymentStream(
        $response->getBody(),
        static fn () => $response->getBody()->close(),
        $response->getHeaderLine('X-Orbit-Request-Id'),
        silenceLimitSeconds: $silenceLimitSeconds,
    );
}
