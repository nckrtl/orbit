<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Exceptions\GatewayConfigException;
use Closure;

final readonly class GatewayConfigLock
{
    private const int LOCK_RETRY_MICROSECONDS = 10_000;

    private const int LOCK_TIMEOUT_NANOSECONDS = 5_000_000_000;

    public function __construct(
        private string $configPath,
    ) {}

    public function synchronized(Closure $operation): void
    {
        $this->ensurePrivateDirectory();
        $lock = $this->acquire();

        try {
            $operation();
        } finally {
            try {
                flock($lock, LOCK_UN);
            } finally {
                fclose($lock);
            }
        }
    }

    public function ensurePrivateDirectory(): string
    {
        $directory = dirname($this->configPath);
        $directoryCreated = false;

        if (! is_dir($directory)) {
            $directoryCreated = mkdir(directory: $directory, permissions: 0o700, recursive: true);

            if (! $directoryCreated && ! is_dir($directory)) {
                throw new GatewayConfigException('Could not update Orbit gateway configuration.');
            }
        }

        if ($directoryCreated && ! chmod(filename: $directory, permissions: 0o700)) {
            throw new GatewayConfigException('Could not update Orbit gateway configuration.');
        }

        if ($directoryCreated) {
            return $directory;
        }

        $permissions = fileperms($directory);
        $owner = fileowner($directory);
        $effectiveUserId = function_exists('posix_geteuid') ? posix_geteuid() : null;

        if (
            is_link($directory)
            || ! is_int($permissions)
            || ($permissions & 0o700) !== 0o700
            || ($permissions & 0o067) !== 0
            || ! is_int($owner)
            || ! is_int($effectiveUserId)
            || $owner !== $effectiveUserId
        ) {
            throw new GatewayConfigException('Orbit gateway configuration directory is not private.');
        }

        return $directory;
    }

    /** @return resource */
    private function acquire()
    {
        $lock = $this->open();
        $deadline = (int) hrtime(true) + self::LOCK_TIMEOUT_NANOSECONDS;

        while (true) {
            $wouldBlock = 0;

            if (flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
                return $lock;
            }

            if ($wouldBlock !== 1) {
                fclose($lock);

                throw new GatewayConfigException('Could not lock Orbit gateway configuration.');
            }

            $remainingNanoseconds = $deadline - (int) hrtime(true);

            if ($remainingNanoseconds <= 0) {
                fclose($lock);

                throw new GatewayConfigException(
                    'Timed out after 5 seconds waiting to update Orbit gateway configuration.',
                );
            }

            usleep(min(
                self::LOCK_RETRY_MICROSECONDS,
                max(1, intdiv(num1: $remainingNanoseconds, num2: 1_000)),
            ));
        }
    }

    /** @return resource */
    private function open()
    {
        $lockPath = $this->configPath.'.lock';
        $previousUmask = umask(0o077);
        set_error_handler(static fn (): bool => true);

        try {
            $lock = fopen(filename: $lockPath, mode: 'x+b');
        } finally {
            restore_error_handler();
            umask($previousUmask);
        }

        if (! is_resource($lock)) {
            set_error_handler(static fn (): bool => true);

            try {
                $lock = fopen(filename: $lockPath, mode: 'rb');
            } finally {
                restore_error_handler();
            }
        }

        if (! is_resource($lock)) {
            throw new GatewayConfigException('Could not lock Orbit gateway configuration.');
        }

        $this->assertPrivate($lockPath, $lock);

        return $lock;
    }

    /** @param resource $lock */
    private function assertPrivate(string $lockPath, $lock): void
    {
        clearstatcache(true, $lockPath);
        set_error_handler(static fn (): bool => true);

        try {
            $pathStat = lstat($lockPath);
        } finally {
            restore_error_handler();
        }

        $handleStat = fstat($lock);
        $effectiveUserId = function_exists('posix_geteuid') ? posix_geteuid() : null;

        if (
            is_link($lockPath)
            || ! is_array($pathStat)
            || ! is_array($handleStat)
            || ($handleStat['mode'] & 0o170_000) !== 0o100_000
            || ($handleStat['mode'] & 0o777) !== 0o600
            || ! is_int($effectiveUserId)
            || $handleStat['uid'] !== $effectiveUserId
            || $pathStat['dev'] !== $handleStat['dev']
            || $pathStat['ino'] !== $handleStat['ino']
        ) {
            fclose($lock);

            throw new GatewayConfigException('Orbit gateway configuration lock is not private.');
        }
    }
}
