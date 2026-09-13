<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsAnswer;
use App\Infrastructure\AppDev\PrivateDnsMessageCodec;
use App\Infrastructure\AppDev\SocketPrivateDnsUpstream;
use RuntimeException;

it('returns a UDP upstream answer without waiting for the socket to close', function (): void {
    $codec = new PrivateDnsMessageCodec;
    $query = $codec->encodeQuery('example.com');
    $response = $codec->encodeAnswer($codec->decodeQuestion($query), PrivateDnsAnswer::a('1.2.3.4'));
    $responder = orb313_start_udp_responder($response, holdSeconds: 4.0);

    try {
        $started = hrtime(true);
        $resolved = new SocketPrivateDnsUpstream('127.0.0.1', $responder['port'], 1.5)->resolve($query);
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        expect($resolved)
            ->toBe($response)
            ->and($elapsed)
            ->toBeLessThan(0.5);
    } finally {
        orb313_stop_process($responder);
    }
});

it('fails a silent UDP upstream without blocking the timeout window twice', function (): void {
    $silent = stream_socket_server('udp://127.0.0.1:0', $error, $message, STREAM_SERVER_BIND);
    expect($silent)->toBeResource();
    $name = stream_socket_get_name($silent, false);
    expect($name)->toBeString();
    $port = (int) substr($name, strrpos($name, ':') + 1);

    try {
        $started = hrtime(true);
        expect(fn () => new SocketPrivateDnsUpstream('127.0.0.1', $port, 0.2)->resolve(
            new PrivateDnsMessageCodec()->encodeQuery('example.com'),
        ))->toThrow(RuntimeException::class);
        expect((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(1.0);
    } finally {
        fclose($silent);
    }
});

it('gives up on a TCP upstream that accepts but never sends a response', function (): void {
    $codec = new PrivateDnsMessageCodec;
    $query = $codec->encodeQuery('example.com');
    $responder = orb313_start_stalled_tcp_acceptor();

    try {
        $started = hrtime(true);
        expect(fn () => new SocketPrivateDnsUpstream('127.0.0.1', $responder['port'], 0.2)->resolve($query))
            ->toThrow(RuntimeException::class);
        expect((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(1.0);
    } finally {
        orb313_stop_process($responder);
    }
});

/**
 * @return array{process: resource, pipes: array<int, resource>, port: int, files: list<string>}
 */
function orb313_start_udp_responder(string $response, float $holdSeconds): array
{
    $script = <<<'PHP'
        <?php

        declare(strict_types=1);

        $portFile = $argv[1];
        $response = hex2bin($argv[2]);
        $holdSeconds = (float) $argv[3];
        if (! is_string($response)) {
            exit(1);
        }

        $server = stream_socket_server('udp://127.0.0.1:0', $error, $message, STREAM_SERVER_BIND);
        if (! is_resource($server)) {
            exit(1);
        }

        $name = stream_socket_get_name($server, false);
        if (! is_string($name) || ! str_contains($name, ':')) {
            exit(1);
        }

        file_put_contents($portFile, (string) ((int) substr($name, strrpos($name, ':') + 1)));
        $peer = '';
        $message = stream_socket_recvfrom($server, 65535, 0, $peer);
        if (is_string($message) && $peer !== '') {
            stream_socket_sendto($server, $response, 0, $peer);
        }

        usleep((int) ($holdSeconds * 1_000_000));
        PHP;

    return orb313_start_script($script, [bin2hex($response), (string) $holdSeconds]);
}

/**
 * @return array{process: resource, pipes: array<int, resource>, port: int, files: list<string>}
 */
function orb313_start_stalled_tcp_acceptor(): array
{
    $script = <<<'PHP'
        <?php

        declare(strict_types=1);

        $portFile = $argv[1];
        $server = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        if (! is_resource($server)) {
            exit(1);
        }

        $name = stream_socket_get_name($server, false);
        if (! is_string($name) || ! str_contains($name, ':')) {
            exit(1);
        }

        file_put_contents($portFile, (string) ((int) substr($name, strrpos($name, ':') + 1)));
        $connection = @stream_socket_accept($server, 5);
        if (is_resource($connection)) {
            sleep(5);
            fclose($connection);
        }
        PHP;

    return orb313_start_script($script, []);
}

/**
 * @param  list<string>  $arguments
 * @return array{process: resource, pipes: array<int, resource>, port: int, files: list<string>}
 */
function orb313_start_script(string $script, array $arguments): array
{
    $scriptPath = tempnam(sys_get_temp_dir(), 'orbit-dns-script-');
    $portFile = tempnam(sys_get_temp_dir(), 'orbit-dns-port-');
    expect($scriptPath)->toBeString()->and($portFile)->toBeString();
    file_put_contents($scriptPath, $script);
    file_put_contents($portFile, '');

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $scriptPath, $portFile, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    expect($process)->toBeResource();

    $port = 0;
    $deadline = microtime(true) + 2.0;
    while (microtime(true) < $deadline) {
        clearstatcache(true, $portFile);
        $contents = file_get_contents($portFile);
        if (is_string($contents) && ctype_digit(trim($contents))) {
            $port = (int) trim($contents);

            break;
        }

        usleep(10_000);
    }

    expect($port)->toBeGreaterThan(0);

    return [
        'process' => $process,
        'pipes' => $pipes,
        'port' => $port,
        'files' => [$scriptPath, $portFile],
    ];
}

/**
 * @param  array{process: resource, pipes: array<int, resource>, port: int, files: list<string>}  $started
 */
function orb313_stop_process(array $started): void
{
    foreach ($started['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    proc_terminate($started['process']);
    proc_close($started['process']);

    foreach ($started['files'] as $path) {
        @unlink($path);
    }
}
