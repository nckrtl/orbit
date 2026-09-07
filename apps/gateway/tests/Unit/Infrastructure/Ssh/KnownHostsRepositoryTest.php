<?php

declare(strict_types=1);

use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('installs and replaces pinned host keys atomically', function (): void {
    $directory = sys_get_temp_dir().'/orbit-known-hosts-'.Str::uuid();
    $path = $directory.'/ssh/known_hosts';
    $repository = new KnownHostsRepository($path);

    try {
        $repository->put('10.44.0.3', 22, new HostKey('ssh-ed25519', 'FIRST', 'SHA256:first'));
        $repository->put('10.44.0.3', 22, new HostKey('ssh-ed25519', 'SECOND', 'SHA256:second'));

        expect(file_get_contents($path))->toBe("10.44.0.3 ssh-ed25519 SECOND\n");
        expect(fileperms($path) & 0o777)->toBe(0o600);
        expect(fileperms(dirname($path)) & 0o777)->toBe(0o700);
        expect(fileperms($path.'.lock') & 0o777)->toBe(0o600);
        expect(is_file($path.'.lock'))->toBeTrue();
        expect(known_hosts_candidates($path))->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});

it('preserves existing entries when replacing a non-default port', function (): void {
    $directory = sys_get_temp_dir().'/orbit-known-hosts-'.Str::uuid();
    $path = $directory.'/ssh/known_hosts';
    mkdir(dirname($path), permissions: 0o700, recursive: true);
    file_put_contents($path, "10.44.0.2 ssh-ed25519 EXISTING\n10.44.0.3 ssh-rsa ORIGINAL\n");
    $repository = new KnownHostsRepository($path);

    try {
        $repository->put('example.test', 2222, new HostKey('ssh-ed25519', 'FIRST', 'SHA256:first'));
        $repository->put('example.test', 2222, new HostKey('ssh-ed25519', 'SECOND', 'SHA256:second'));

        expect(file_get_contents($path))
            ->toBe(
                "10.44.0.2 ssh-ed25519 EXISTING\n"
                ."10.44.0.3 ssh-rsa ORIGINAL\n"
                ."[example.test]:2222 ssh-ed25519 SECOND\n",
            );
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});

it('preserves every addition from ten concurrent writers', function (): void {
    $directory = sys_get_temp_dir().'/orbit-known-hosts-'.Str::uuid();
    $path = $directory.'/ssh/known_hosts';
    $repository = new KnownHostsRepository($path);
    $repository->put('10.44.0.1', 22, new HostKey('ssh-ed25519', 'EXISTING', 'SHA256:existing'));
    $writes = [];

    for ($index = 0; $index < 10; $index++) {
        $writes[] = [
            'host' => '10.44.0.'.($index + 2),
            'port' => 22,
            'key' => "KEY-{$index}",
        ];
    }

    try {
        known_hosts_run_contending_writers($path, $directory, $writes);
        $actual = preg_split('/\R/', trim((string) file_get_contents($path)));
        $expected = ['10.44.0.1 ssh-ed25519 EXISTING'];

        foreach ($writes as $write) {
            $expected[] = "{$write['host']} ssh-ed25519 {$write['key']}";
        }

        sort($actual);
        sort($expected);

        expect($actual)->toBe($expected);
        expect(known_hosts_candidates($path))->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});

it('keeps one complete entry during same-host contention without corrupting other hosts', function (): void {
    $directory = sys_get_temp_dir().'/orbit-known-hosts-'.Str::uuid();
    $path = $directory.'/ssh/known_hosts';
    $repository = new KnownHostsRepository($path);
    $repository->put('10.44.0.1', 22, new HostKey('ssh-ed25519', 'EXISTING', 'SHA256:existing'));
    $writes = [];

    for ($index = 0; $index < 10; $index++) {
        $writes[] = [
            'host' => '10.44.0.9',
            'port' => 22,
            'key' => "KEY-{$index}",
        ];
    }

    try {
        known_hosts_run_contending_writers($path, $directory, $writes);
        $lines = preg_split('/\R/', trim((string) file_get_contents($path)));
        $validContendedEntries = array_map(
            static fn (array $write): string => "10.44.0.9 ssh-ed25519 {$write['key']}",
            $writes,
        );

        expect($lines)->toHaveCount(2);
        expect($lines[0])->toBe('10.44.0.1 ssh-ed25519 EXISTING');
        expect(in_array($lines[1], $validContendedEntries, true))->toBeTrue();
        expect(known_hosts_candidates($path))->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});

it('times out after ten seconds without changing the destination', function (): void {
    $directory = sys_get_temp_dir().'/orbit-known-hosts-'.Str::uuid();
    $path = $directory.'/ssh/known_hosts';
    $repository = new KnownHostsRepository($path);
    $repository->put('10.44.0.1', 22, new HostKey('ssh-ed25519', 'EXISTING', 'SHA256:existing'));
    $original = file_get_contents($path);
    $lock = known_hosts_test_lock($path);
    $exception = null;
    $startedAt = hrtime(true);

    try {
        $repository->put('10.44.0.2', 22, new HostKey('ssh-ed25519', 'WAITING', 'SHA256:waiting'));
    } catch (RuntimeException $caught) {
        $exception = $caught;
    } finally {
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    try {
        expect($exception)->toBeInstanceOf(RuntimeException::class);
        expect($exception?->getMessage())
            ->toBe("Timed out after 10 seconds waiting to update SSH host keys [{$path}].");
        expect($elapsedSeconds)->toBeGreaterThanOrEqual(9.9)->toBeLessThan(12.0);
        expect(file_get_contents($path))->toBe($original);
        expect(known_hosts_candidates($path))->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});

it('proceeds promptly after a preceding writer releases the lock and reads its update', function (): void {
    $directory = sys_get_temp_dir().'/orbit-known-hosts-'.Str::uuid();
    $path = $directory.'/ssh/known_hosts';
    $repository = new KnownHostsRepository($path);
    $repository->put('10.44.0.1', 22, new HostKey('ssh-ed25519', 'ORIGINAL', 'SHA256:original'));
    $lock = known_hosts_test_lock($path);
    $markerDirectory = $directory.'/waiter';
    mkdir($markerDirectory, permissions: 0o700);
    $startPath = $markerDirectory.'/start';
    $readyPath = $markerDirectory.'/ready';
    $attemptedPath = $markerDirectory.'/attempted';
    $process = known_hosts_writer_process(
        $path,
        ['host' => '10.44.0.3', 'port' => 22, 'key' => 'WAITER'],
        ['ready' => $readyPath, 'start' => $startPath, 'attempted' => $attemptedPath],
    );
    $locked = true;

    try {
        $process->start();
        known_hosts_wait_until(static fn (): bool => is_file($readyPath));
        file_put_contents($startPath, 'start');
        known_hosts_wait_until(static fn (): bool => is_file($attemptedPath));
        expect($process->isRunning())->toBeTrue();

        file_put_contents(
            $path,
            "10.44.0.1 ssh-ed25519 ORIGINAL\n10.44.0.2 ssh-ed25519 PRECEDING\n",
        );
        $releasedAt = hrtime(true);
        flock($lock, LOCK_UN);
        $locked = false;
        known_hosts_wait_for_success($process);
        $elapsedSeconds = (hrtime(true) - $releasedAt) / 1_000_000_000;

        expect($elapsedSeconds)->toBeLessThan(2.0);
        expect(file_get_contents($path))
            ->toBe(
                "10.44.0.1 ssh-ed25519 ORIGINAL\n"."10.44.0.2 ssh-ed25519 PRECEDING\n"."10.44.0.3 ssh-ed25519 WAITER\n",
            );
    } finally {
        if ($locked) {
            flock($lock, LOCK_UN);
        }

        fclose($lock);

        if ($process->isRunning()) {
            $process->stop(0.1, 9);
        }

        new Filesystem()->deleteDirectory($directory);
    }
});

it('releases the lock and cleans the candidate when replacement fails', function (): void {
    $directory = sys_get_temp_dir().'/orbit-known-hosts-'.Str::uuid();
    $path = $directory.'/ssh/known_hosts';
    mkdir($path, permissions: 0o700, recursive: true);
    file_put_contents($path.'/prior-entry', 'complete');
    $repository = new KnownHostsRepository($path);

    try {
        set_error_handler(static fn (): bool => true);

        try {
            expect(fn () => $repository->put(
                '10.44.0.2',
                22,
                new HostKey('ssh-ed25519', 'FAILED', 'SHA256:failed'),
            ))
                ->toThrow(RuntimeException::class, "Could not install SSH host keys [{$path}].");
        } finally {
            restore_error_handler();
        }

        $lock = fopen($path.'.lock', 'c');
        expect(is_resource($lock))->toBeTrue();

        if (! is_resource($lock)) {
            throw new RuntimeException('Could not reopen the known-hosts test lock.');
        }

        try {
            expect(flock($lock, LOCK_EX | LOCK_NB))->toBeTrue();
        } finally {
            fclose($lock);
        }

        expect(file_get_contents($path.'/prior-entry'))->toBe('complete');
        expect(known_hosts_candidates($path))->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});

it('releases the stable lock and preserves the destination when a writer process terminates', function (): void {
    $directory = sys_get_temp_dir().'/orbit-known-hosts-'.Str::uuid();
    $path = $directory.'/ssh/known_hosts';
    $repository = new KnownHostsRepository($path);
    $repository->put('10.44.0.1', 22, new HostKey('ssh-ed25519', 'EXISTING', 'SHA256:existing'));
    $original = file_get_contents($path);
    $candidatePath = $path.'.candidate.interrupted';
    $readyPath = $directory.'/holder-ready';
    $process = known_hosts_terminating_writer_process($path, $candidatePath, $readyPath);

    try {
        $process->start();
        known_hosts_wait_until(static fn (): bool => is_file($readyPath));
        expect($process->isRunning())->toBeTrue();
        expect(file_get_contents($candidatePath))->toBe('partial');

        $contender = fopen($path.'.lock', 'c');
        expect(is_resource($contender))->toBeTrue();

        if (! is_resource($contender)) {
            throw new RuntimeException('Could not open the known-hosts contender lock.');
        }

        try {
            $wouldBlock = 0;
            expect(flock($contender, LOCK_EX | LOCK_NB, $wouldBlock))->toBeFalse();
            expect($wouldBlock)->toBe(1);
        } finally {
            fclose($contender);
        }

        $process->signal(9);
        $process->wait();
        expect(file_get_contents($path))->toBe($original);

        unlink($candidatePath);
        $startedAt = hrtime(true);
        $repository->put('10.44.0.2', 22, new HostKey('ssh-ed25519', 'AFTER', 'SHA256:after'));
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        expect($elapsedSeconds)->toBeLessThan(2.0);
        expect(file_get_contents($path))
            ->toBe(
                "10.44.0.1 ssh-ed25519 EXISTING\n10.44.0.2 ssh-ed25519 AFTER\n",
            );
        expect(is_file($path.'.lock'))->toBeTrue();
    } finally {
        if ($process->isRunning()) {
            $process->stop(0.1, 9);
        }

        new Filesystem()->deleteDirectory($directory);
    }
});

/** @return list<string> */
function known_hosts_candidates(string $path): array
{
    $candidates = glob($path.'.candidate.*');

    return is_array($candidates) ? $candidates : [];
}

/** @param list<array{host: string, port: int, key: string}> $writes */
function known_hosts_run_contending_writers(string $path, string $directory, array $writes): void
{
    $lock = known_hosts_test_lock($path);
    $markerDirectory = $directory.'/writers-'.Str::uuid();
    mkdir($markerDirectory, permissions: 0o700);
    $startPath = $markerDirectory.'/start';
    $processes = [];
    $locked = true;

    try {
        foreach ($writes as $index => $write) {
            $process = known_hosts_writer_process(
                $path,
                $write,
                [
                    'ready' => "{$markerDirectory}/ready-{$index}",
                    'start' => $startPath,
                    'attempted' => "{$markerDirectory}/attempted-{$index}",
                ],
            );
            $process->start();
            $processes[] = $process;
        }

        known_hosts_wait_until(
            static fn (): bool => count(glob($markerDirectory.'/ready-*') ?: []) === count($writes),
        );
        file_put_contents($startPath, 'start');
        known_hosts_wait_until(
            static fn (): bool => count(glob($markerDirectory.'/attempted-*') ?: []) === count($writes),
        );

        foreach ($processes as $process) {
            expect($process->isRunning())->toBeTrue();
        }

        flock($lock, LOCK_UN);
        $locked = false;

        foreach ($processes as $process) {
            known_hosts_wait_for_success($process);
        }
    } finally {
        if ($locked) {
            flock($lock, LOCK_UN);
        }

        fclose($lock);

        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.1, 9);
            }
        }
    }
}

/**
 * @param array{host: string, port: int, key: string} $write
 * @param array{ready: string, start: string, attempted: string} $markers
 */
function known_hosts_writer_process(string $path, array $write, array $markers): Process
{
    $code = <<<'PHP'
        require $argv[1];

        file_put_contents($argv[6], 'ready');

        while (! is_file($argv[7])) {
            usleep(1_000);
        }

        file_put_contents($argv[8], 'attempted');

        try {
            $repository = new App\Infrastructure\Ssh\KnownHostsRepository($argv[2]);
            $repository->put(
                $argv[3],
                (int) $argv[4],
                new App\Infrastructure\Ssh\HostKey('ssh-ed25519', $argv[5], 'SHA256:worker'),
            );
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception::class.': '.$exception->getMessage());
            exit(1);
        }
        PHP;

    return new Process([
        PHP_BINARY,
        '-r',
        $code,
        dirname(__DIR__, 4).'/vendor/autoload.php',
        $path,
        $write['host'],
        (string) $write['port'],
        $write['key'],
        $markers['ready'],
        $markers['start'],
        $markers['attempted'],
    ], timeout: 15);
}

function known_hosts_terminating_writer_process(string $path, string $candidatePath, string $readyPath): Process
{
    $code = <<<'PHP'
        $lock = fopen($argv[1].'.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            fwrite(STDERR, 'Could not acquire the known-hosts test lock.');
            exit(1);
        }

        file_put_contents($argv[2], 'partial');
        file_put_contents($argv[3], 'ready');

        while (true) {
            usleep(10_000);
        }
        PHP;

    return new Process([
        PHP_BINARY,
        '-r',
        $code,
        $path,
        $candidatePath,
        $readyPath,
    ], timeout: 15);
}

/** @return resource */
function known_hosts_test_lock(string $path)
{
    $lock = fopen($path.'.lock', 'c');

    if ($lock === false || ! flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not acquire the known-hosts test lock.');
    }

    return $lock;
}

function known_hosts_wait_for_success(Process $process): void
{
    $exitCode = $process->wait();

    if ($exitCode !== 0) {
        throw new RuntimeException(
            "Known-hosts writer failed with exit code {$exitCode}: {$process->getErrorOutput()}",
        );
    }
}

function known_hosts_wait_until(Closure $condition, float $timeoutSeconds = 5.0): void
{
    $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);

    while (! $condition()) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the known-hosts test condition.');
        }

        usleep(1_000);
    }
}
