<?php

declare(strict_types=1);

namespace App\Infrastructure\Clusters;

use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use Closure;
use LogicException;
use RuntimeException;

final class NativeClusterRouterOperationLock implements ClusterRouterOperationLock
{
    /** @var array<int, positive-int> */
    private array $depths = [];

    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @var Closure(int): void */
    private readonly Closure $wait;

    /**
     * @param (Closure(): float)|null $clock
     * @param (Closure(int): void)|null $wait
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

    public function run(int $clusterId, Closure $operation): mixed
    {
        if (array_key_exists($clusterId, $this->depths)) {
            $this->depths[$clusterId]++;

            try {
                return $operation();
            } finally {
                $this->depths[$clusterId]--;
            }
        }

        if ($this->depths !== []) {
            throw new LogicException('A Cluster Router operation cannot acquire a second Cluster owner.');
        }

        $this->prepareDirectory();
        $path = "{$this->directory}/cluster-{$clusterId}.lock";
        $handle = fopen(filename: $path, mode: 'c+');

        if ($handle === false) {
            throw new RuntimeException("Could not open Cluster Router lock [{$path}].");
        }

        try {
            if (! chmod(filename: $path, permissions: 0o600)) {
                throw new RuntimeException("Could not protect Cluster Router lock [{$path}].");
            }

            $this->acquire($handle);
            $this->depths[$clusterId] = 1;

            try {
                return $operation();
            } finally {
                unset($this->depths[$clusterId]);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function prepareDirectory(): void
    {
        if ($this->directory === '') {
            throw new RuntimeException('The Cluster Router lock directory is not configured.');
        }

        if (
            ! is_dir($this->directory)
            && ! mkdir(directory: $this->directory, permissions: 0o700, recursive: true)
            && ! is_dir($this->directory)
        ) {
            throw new RuntimeException("Could not create Cluster Router lock directory [{$this->directory}].");
        }

        if (! chmod(filename: $this->directory, permissions: 0o700)) {
            throw new RuntimeException("Could not protect Cluster Router lock directory [{$this->directory}].");
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
            errorCode: 'cluster.router_busy',
            message: 'Another Cluster Router operation is active. Retry the request.',
            status: 409,
        );
    }
}
