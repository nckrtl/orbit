<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\PrivateDnsListenerRelease;
use App\Infrastructure\AppDev\PrivateDnsMessageCodec;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('builds the release from the listener classes only, with an id that follows their contents', function (): void {
    $release = PrivateDnsListenerRelease::fromGateway();
    $files = $release->files();

    expect($files)->toHaveKeys([
        'serve.php',
        'app/Infrastructure/AppDev/PrivateDnsListenerProcess.php',
        'app/Infrastructure/AppDev/FilePrivateDnsCatalogStore.php',
        'app/Infrastructure/AppDev/PrivateDnsInheritedSockets.php',
        'app/Infrastructure/AppDev/WireGuardDnsRequesterResolver.php',
    ])
        ->and(array_filter(array_keys($files), static fn (string $path): bool => str_starts_with($path, 'app/Models/')))->toBe([])
        ->and($release->id())->toMatch('/\A[0-9a-f]{16}\z/')
        ->and(PrivateDnsListenerRelease::fromGateway()->id())->toBe($release->id());

    $root = sys_get_temp_dir().'/orbit-release-source-'.bin2hex(random_bytes(8));
    $disk = new Filesystem;
    try {
        foreach ($files as $path => $contents) {
            $disk->ensureDirectoryExists(dirname($root.'/'.$path));
            $disk->put($root.'/'.$path, $contents);
        }
        $disk->ensureDirectoryExists($root.'/resources/private-dns');
        $disk->put($root.'/resources/private-dns/serve.php', $files['serve.php']);
        $disk->append($root.'/app/Infrastructure/AppDev/PrivateDnsListenerProcess.php', "\n// changed\n");

        expect(new PrivateDnsListenerRelease($root)->id())->not->toBe($release->id());
    } finally {
        $disk->deleteDirectory($root);
    }
});

it('runs from the release without the Gateway autoloader and serves a published catalog', function (): void {
    $directory = orb_release_install();
    $catalog = $directory.'/catalog.json';
    file_put_contents($catalog, '{"requesters":{},"records":{"release.orbit":"10.44.0.9"},"suffixes":{},"overrides":{}}');
    $port = orb_release_free_port();
    $selfTest = new Process([PHP_BINARY, $directory.'/serve.php', '--self-test']);
    $selfTest->run();
    $listener = new Process([PHP_BINARY, $directory.'/serve.php', '--listen=127.0.0.1', '--port='.$port, '--catalog='.$catalog, '--upstream=127.0.0.1:9']);

    try {
        expect($selfTest->getExitCode())->toBe(0, $selfTest->getErrorOutput());
        $listener->start();
        expect(orb_release_wait_until(static fn (): bool => orb_release_query($port, 'release.orbit') === '10.44.0.9', 5.0))->toBeTrue();

        expect(orb_release_query($port, 'release.orbit'))->toBe('10.44.0.9')
            ->and(trim((string) file_get_contents($catalog.'.loaded')))->toBe(hash_file('sha256', $catalog));

        $listener->signal(SIGTERM);
        expect(orb_release_wait_until(static fn (): bool => ! $listener->isRunning(), 5.0))->toBeTrue()
            ->and($listener->getExitCode())->toBe(0);
    } finally {
        if ($listener->isRunning()) {
            $listener->stop(0.5);
        }
        new Filesystem()->deleteDirectory($directory);
    }
});

it('answers a query that arrives while the listener restarts on sockets it inherited', function (): void {
    $directory = orb_release_install();
    $catalog = $directory.'/catalog.json';
    file_put_contents($catalog, '{"requesters":{},"records":{"handover.orbit":"10.44.0.9"},"suffixes":{},"overrides":{}}');
    $udp = stream_socket_server('udp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND);
    expect($udp)->toBeResource();
    $port = (int) substr((string) stream_socket_get_name($udp, false), strrpos((string) stream_socket_get_name($udp, false), ':') + 1);
    $tcp = stream_socket_server('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage);
    expect($tcp)->toBeResource();
    $start = static fn () => proc_open(
        ['sh', '-c', 'LISTEN_PID=$$ LISTEN_FDS=2 exec "$0" "$@"', PHP_BINARY, $directory.'/serve.php', '--listen=127.0.0.1', '--port='.$port, '--catalog='.$catalog, '--upstream=127.0.0.1:9'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory.'/listener.log', 'a'], 3 => $udp, 4 => $tcp],
        $pipes,
    );

    $first = $start();
    try {
        expect(orb_release_query($port, 'handover.orbit'))->toBe('10.44.0.9');

        // The first listener stops, a query arrives, and only then the next listener starts on the same sockets.
        proc_terminate($first, SIGTERM);
        expect(orb_release_wait_until(static fn (): bool => ! proc_get_status($first)['running'], 5.0))->toBeTrue();
        $client = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
        $query = new PrivateDnsMessageCodec()->encodeQuery('handover.orbit');
        socket_sendto($client, $query, strlen($query), 0, '127.0.0.1', $port);
        $second = $start();
        $response = '';
        $from = '';
        $fromPort = 0;
        socket_recvfrom($client, $response, 4096, 0, $from, $fromPort);
        socket_close($client);

        expect(long2ip(unpack('Nip', substr($response, -4))['ip']))->toBe('10.44.0.9')
            ->and(orb_release_dig($port, 'handover.orbit', 'tcp'))->toBe('10.44.0.9');
        proc_terminate($second, SIGTERM);
    } finally {
        foreach ([$first, $second ?? null] as $process) {
            if (is_resource($process)) {
                proc_terminate($process, SIGKILL);
                proc_close($process);
            }
        }
        fclose($udp);
        fclose($tcp);
        new Filesystem()->deleteDirectory($directory);
    }
});

function orb_release_install(): string
{
    $directory = sys_get_temp_dir().'/orbit-release-'.bin2hex(random_bytes(8));
    $disk = new Filesystem;
    foreach (PrivateDnsListenerRelease::fromGateway()->files() as $path => $contents) {
        $disk->ensureDirectoryExists(dirname($directory.'/'.$path));
        $disk->put($directory.'/'.$path, $contents);
    }

    return $directory;
}

function orb_release_query(int $port, string $name): string
{
    return orb_release_dig($port, $name, 'udp');
}

function orb_release_dig(int $port, string $name, string $transport): string
{
    $command = ['dig', '+time=1', '+tries=1', '+short', '-p', (string) $port, '@127.0.0.1', $name, 'A'];
    if ($transport === 'tcp') {
        $command[] = '+tcp';
    }

    $process = new Process($command);
    $process->run();

    return trim($process->getOutput());
}

function orb_release_free_port(): int
{
    $udp = stream_socket_server('udp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND);
    $name = (string) stream_socket_get_name($udp, false);
    fclose($udp);

    return (int) substr($name, strrpos($name, ':') + 1);
}

function orb_release_wait_until(callable $ready, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        if ($ready()) {
            return true;
        }
        usleep(50_000);
    }

    return (bool) $ready();
}
