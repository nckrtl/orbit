<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use Illuminate\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\Lock;

/**
 * One per-Node lock from NodeLocks. While this process holds it, NodeLocks renews it before each
 * command the process runs, so a long operation keeps it for as long as it runs.
 */
final class NodeLock implements Lock
{
    /** Set when a renewal found another owner, so every later command of the operation refuses to run. */
    private bool $lost = false;

    public function __construct(
        private readonly NodeLocks $locks,
        private readonly CacheLock $lock,
        private readonly string $name,
    ) {}

    public function get($callback = null): mixed
    {
        if (! $this->lock->get()) {
            return false;
        }

        $this->held();

        if ($callback === null) {
            return true;
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    /** Waits up to the given number of seconds, polling like Laravel's own lock. */
    public function block($seconds, $callback = null): mixed
    {
        $this->lock->block($seconds);
        $this->held();

        if ($callback === null) {
            return true;
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    public function release(): bool
    {
        $this->locks->forget($this->name, $this);

        return $this->lock->release();
    }

    public function owner(): string
    {
        return $this->lock->owner();
    }

    public function forceRelease(): void
    {
        $this->locks->forget($this->name, $this);
        $this->lock->forceRelease();
    }

    /**
     * Extends the lock by its full term. False, now and on every later call, when the lock expired and
     * another operation took it.
     */
    public function refresh(): bool
    {
        if ($this->lost) {
            return false;
        }

        if (! $this->lock->refresh()) {
            $this->lost = true;
        }

        return ! $this->lost;
    }

    public function name(): string
    {
        return $this->name;
    }

    private function held(): void
    {
        $this->lost = false;
        $this->locks->hold($this->name, $this);
    }
}
