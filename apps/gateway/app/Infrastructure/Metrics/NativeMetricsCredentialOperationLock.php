<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\MetricsCredentialOperationLock;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use Closure;
use LogicException;
use RuntimeException;

final class NativeMetricsCredentialOperationLock implements MetricsCredentialOperationLock
{
    /** @var array<int, positive-int> */
    private array $depths = [];

    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @var Closure(int): void */
    private readonly Closure $wait;

    /**
     * @param  (Closure(): float)|null  $clock
     * @param  (Closure(int): void)|null  $wait
     */
    public function __construct(
        private readonly string $directory,
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

    public function run(int $nodeId, Closure $operation): mixed
    {
        if ($nodeId < 1) {
            throw new LogicException('A Metrics credential owner requires a positive Node identifier.');
        }

        if (array_key_exists($nodeId, $this->depths)) {
            $this->depths[$nodeId]++;

            try {
                return $operation();
            } finally {
                $this->depths[$nodeId]--;
            }
        }

        if ($this->depths !== []) {
            throw new LogicException('A Metrics credential operation cannot acquire a second Node owner.');
        }

        $this->prepareDirectory();
        $path = "{$this->directory}/node-{$nodeId}.lock";
        $handle = fopen(filename: $path, mode: 'c+');

        if ($handle === false) {
            throw new RuntimeException("Could not open Metrics credential lock [{$path}].");
        }

        try {
            if (! chmod(filename: $path, permissions: 0o600)) {
                throw new RuntimeException("Could not protect Metrics credential lock [{$path}].");
            }

            $this->acquire($handle);
            $this->depths[$nodeId] = 1;

            try {
                return $operation();
            } finally {
                unset($this->depths[$nodeId]);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function prepareDirectory(): void
    {
        if ($this->directory === '') {
            throw new RuntimeException('The Metrics credential lock directory is not configured.');
        }

        if (
            ! is_dir($this->directory)
            && ! mkdir(directory: $this->directory, permissions: 0o700, recursive: true)
            && ! is_dir($this->directory)
        ) {
            throw new RuntimeException("Could not create Metrics credential lock directory [{$this->directory}].");
        }

        if (! chmod(filename: $this->directory, permissions: 0o700)) {
            throw new RuntimeException("Could not protect Metrics credential lock directory [{$this->directory}].");
        }
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
            errorCode: 'metrics.credentials_busy',
            message: 'Another Metrics credential operation is active for this Node. Retry the request.',
            status: 409,
        );
    }
}
