<?php

declare(strict_types=1);

namespace App\Infrastructure\Ssh;

use InvalidArgumentException;
use RuntimeException;

final readonly class KnownHostsRepository implements KnownHostsStore
{
    private const int LockRetryMicroseconds = 10_000;

    private const int LockTimeoutNanoseconds = 10_000_000_000;

    public function __construct(
        private string $path,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function put(string $host, int $port, HostKey $key): void
    {
        if ($host === '' || preg_match('/\s/', $host) === 1) {
            throw new InvalidArgumentException('SSH host names cannot contain whitespace.');
        }

        $directory = dirname($this->path);

        if (
            ! is_dir($directory)
            && ! mkdir(directory: $directory, permissions: 0o700, recursive: true)
            && ! is_dir($directory)
        ) {
            throw new RuntimeException("Could not create SSH directory [{$directory}].");
        }

        chmod(filename: $directory, permissions: 0o700);

        $lock = $this->acquireLock();

        try {
            $this->replace($host, $port, $key);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** @return resource */
    private function acquireLock()
    {
        $lockPath = $this->path.'.lock';
        $deadline = $this->monotonicNanoseconds() + self::LockTimeoutNanoseconds;
        $lock = fopen(filename: $lockPath, mode: 'c');

        if ($lock === false) {
            throw new RuntimeException("Could not open SSH host keys lock [{$lockPath}].");
        }

        if (! chmod(filename: $lockPath, permissions: 0o600)) {
            fclose($lock);

            throw new RuntimeException("Could not protect SSH host keys lock [{$lockPath}].");
        }

        while (true) {
            $wouldBlock = 0;

            if (flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
                return $lock;
            }

            if ($wouldBlock !== 1) {
                fclose($lock);

                throw new RuntimeException("Could not acquire SSH host keys lock [{$lockPath}].");
            }

            $remainingNanoseconds = $deadline - $this->monotonicNanoseconds();

            if ($remainingNanoseconds <= 0) {
                fclose($lock);

                throw new RuntimeException(
                    "Timed out after 10 seconds waiting to update SSH host keys [{$this->path}].",
                );
            }

            usleep(min(
                self::LockRetryMicroseconds,
                max(1, intdiv($remainingNanoseconds, 1_000)),
            ));
        }
    }

    /** @param resource $lock */
    private function releaseLock($lock): void
    {
        try {
            flock($lock, LOCK_UN);
        } finally {
            fclose($lock);
        }
    }

    private function replace(string $host, int $port, HostKey $key): void
    {
        $directory = dirname($this->path);
        $hostLabel = $port === 22 ? $host : "[{$host}]:{$port}";
        $lines = $this->lines();
        $lines = array_values(array_filter(
            $lines,
            static fn (string $line): bool => ! str_starts_with($line, $hostLabel.' '),
        ));
        $lines[] = "{$hostLabel} {$key->type} {$key->value}";

        $candidatePath = tempnam(
            directory: $directory,
            prefix: basename($this->path).'.candidate.',
        );

        if ($candidatePath === false) {
            throw new RuntimeException("Could not create SSH host keys candidate [{$this->path}].");
        }

        $contents = implode(PHP_EOL, $lines).PHP_EOL;

        try {
            if (realpath(dirname($candidatePath)) !== realpath($directory)) {
                throw new RuntimeException("Could not create SSH host keys candidate [{$this->path}].");
            }

            $written = file_put_contents($candidatePath, $contents, LOCK_EX);

            if ($written !== strlen($contents)) {
                throw new RuntimeException("Could not write SSH host keys [{$candidatePath}].");
            }

            if (! chmod(filename: $candidatePath, permissions: 0o600)) {
                throw new RuntimeException("Could not protect SSH host keys [{$candidatePath}].");
            }

            if (! rename($candidatePath, $this->path)) {
                throw new RuntimeException("Could not install SSH host keys [{$this->path}].");
            }
        } finally {
            if (is_file($candidatePath)) {
                unlink($candidatePath);
            }
        }
    }

    private function monotonicNanoseconds(): int
    {
        return (int) hrtime(true);
    }

    /** @return list<string> */
    private function lines(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $contents = file_get_contents($this->path);

        if (! is_string($contents)) {
            throw new RuntimeException("Could not read SSH host keys [{$this->path}].");
        }

        $splitLines = preg_split('/\R/', trim($contents));
        $lines = is_array($splitLines) ? $splitLines : [];

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }
}
