<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use Closure;
use RuntimeException;

final class NativeDevelopmentProjectionOperationLock implements DevelopmentProjectionOperationLock
{
    private int $depth = 0;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @var Closure(int): void */
    private readonly Closure $wait;

    /**
     * @param  (Closure(): float)|null  $clock
     * @param  (Closure(int): void)|null  $wait
     */
    public function __construct(
        private readonly string $orbitHome,
        private readonly CommandDeadline $deadline,
        ?Closure $clock = null,
        ?Closure $wait = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->wait = $wait ?? static function (int $microseconds): void {
            if ($microseconds <= 0) {
                return;
            }

            usleep($microseconds);
        };
    }

    public function run(Closure $operation): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;

            try {
                return $operation();
            } finally {
                $this->depth--;
            }
        }

        $this->prepareOrbitHome();
        $path = rtrim(string: $this->orbitHome, characters: '/').'/.dnsmasq-projections.lock';
        $handle = fopen(filename: $path, mode: 'c+');

        if ($handle === false) {
            throw new RuntimeException("Could not open development projection lock [{$path}].");
        }

        try {
            chmod(filename: $path, permissions: 0o600);
            $this->acquire($handle);
            $this->depth = 1;

            try {
                return $operation();
            } finally {
                $this->depth = 0;
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function prepareOrbitHome(): void
    {
        if ($this->orbitHome === '') {
            throw new RuntimeException('The Orbit home is not configured.');
        }

        if (
            ! is_dir($this->orbitHome)
            && ! mkdir(directory: $this->orbitHome, permissions: 0o700, recursive: true)
            && ! is_dir($this->orbitHome)
        ) {
            throw new RuntimeException("Could not create Orbit home [{$this->orbitHome}].");
        }

        chmod(filename: $this->orbitHome, permissions: 0o700);
    }

    /** @param resource $handle */
    private function acquire(mixed $handle): void
    {
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            return;
        }

        try {
            $timeout = $this->deadline->cap(30.0);
        } catch (RuntimeException) {
            throw $this->busy();
        }

        $expiresAt = ($this->clock)() + $timeout;

        while (true) {
            $remaining = $expiresAt - ($this->clock)();

            if ($remaining <= 0.0) {
                throw $this->busy();
            }

            ($this->wait)((int) min(10_000, ceil($remaining * 1_000_000)));

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return;
            }
        }
    }

    private function busy(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'app-dev.projection_busy',
            message: 'Another development projection operation is active. Retry the request.',
            status: 409,
        );
    }
}
