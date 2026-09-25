<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Infrastructure\Processes\CommandDeadline;
use Closure;
use RuntimeException;

/**
 * Serializes Node Caddy builds for one Node on the Gateway. A build waits up to 30 seconds and holds
 * the lock across its render and push, so the last build always renders the latest committed state.
 * A nested build for the same Node reuses the held lock.
 */
final class NodeCaddyBuildLock
{
    public const float WaitSeconds = 30.0;

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
        private readonly CommandDeadline $deadline = new CommandDeadline,
        ?Closure $clock = null,
        ?Closure $wait = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->wait = $wait ?? static function (int $microseconds): void {
            if ($microseconds > 0) {
                usleep($microseconds);
            }
        };
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(int $nodeId, string $nodeName, Closure $operation): mixed
    {
        if (array_key_exists($nodeId, $this->depths)) {
            $this->depths[$nodeId]++;

            try {
                return $operation();
            } finally {
                $this->depths[$nodeId]--;

                if ($this->depths[$nodeId] === 0) {
                    unset($this->depths[$nodeId]);
                }
            }
        }

        $this->prepareDirectory();
        $path = "{$this->directory}/node-{$nodeId}.lock";
        $handle = fopen(filename: $path, mode: 'c+');

        if ($handle === false) {
            throw new RuntimeException("Could not open the Node Caddy build lock [{$path}].");
        }

        try {
            if (! chmod(filename: $path, permissions: 0o600)) {
                throw new RuntimeException("Could not protect the Node Caddy build lock [{$path}].");
            }

            $this->acquire($handle, $nodeName);
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

    /**
     * Runs `$operation` under the Node's lock only when no build holds it now; otherwise returns `$busy`
     * at once. A reader such as Doctor uses it, so it never waits for a build and never makes one wait long.
     *
     * @template T
     * @template B
     *
     * @param  Closure(): T  $operation
     * @param  B  $busy
     * @return T|B
     */
    public function runIfFree(int $nodeId, Closure $operation, mixed $busy = null): mixed
    {
        if (array_key_exists($nodeId, $this->depths)) {
            return $operation();
        }

        $this->prepareDirectory();
        $path = "{$this->directory}/node-{$nodeId}.lock";
        $handle = fopen(filename: $path, mode: 'c+');

        if ($handle === false) {
            throw new RuntimeException("Could not open the Node Caddy build lock [{$path}].");
        }

        try {
            if (! chmod(filename: $path, permissions: 0o600)) {
                throw new RuntimeException("Could not protect the Node Caddy build lock [{$path}].");
            }

            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return $busy;
            }

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
            throw new RuntimeException('The Node Caddy build lock directory is not configured.');
        }

        if (
            ! is_dir($this->directory)
            && ! mkdir(directory: $this->directory, permissions: 0o700, recursive: true)
            && ! is_dir($this->directory)
        ) {
            throw new RuntimeException("Could not create the Node Caddy build lock directory [{$this->directory}].");
        }

        if (! chmod(filename: $this->directory, permissions: 0o700)) {
            throw new RuntimeException("Could not protect the Node Caddy build lock directory [{$this->directory}].");
        }
    }

    /** @param resource $handle */
    private function acquire(mixed $handle, string $nodeName): void
    {
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            return;
        }

        try {
            $timeout = $this->deadline->cap(self::WaitSeconds);
        } catch (RuntimeException) {
            throw $this->busy($nodeName);
        }

        $expiresAt = ($this->clock)() + $timeout;

        while (true) {
            $remaining = $expiresAt - ($this->clock)();

            if ($remaining <= 0.0) {
                throw $this->busy($nodeName);
            }

            ($this->wait)((int) min(10_000, ceil($remaining * 1_000_000)));

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return;
            }
        }
    }

    private function busy(string $nodeName): NodeCaddyBuildException
    {
        return new NodeCaddyBuildException(
            $nodeName,
            'gateway-lock',
            'Another Caddy build for this Node held the lock for 30 seconds.',
        );
    }
}
