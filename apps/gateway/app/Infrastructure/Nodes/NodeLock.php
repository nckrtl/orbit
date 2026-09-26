<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use Illuminate\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/**
 * One per-Node lock from NodeLocks. While this process holds it, NodeLocks renews it before each
 * command the process runs, so a long operation keeps it for as long as it runs.
 */
final class NodeLock implements Lock
{
    /** How often one renewal tries while another process holds the lock file, 20 ms apart. */
    private const int RenewAttempts = 50;

    private const int RenewRetryMicroseconds = 20_000;

    /** Set when a renewal found another owner, so every later command of the operation refuses to run. */
    private bool $lost = false;

    /** When the lock expires unless it is renewed, as a Unix timestamp. */
    private int $expiresAt = 0;

    public function __construct(
        private readonly NodeLocks $locks,
        private readonly CacheLock $lock,
        private readonly string $name,
        private readonly int $seconds,
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
     * Extends the lock by its full term. False, now and on every later call, once the lock has expired,
     * whether or not another operation has taken it since.
     *
     * The file store refuses a renewal while another process briefly holds the lock file, such as an
     * operation that polls for the same lock. The renewal then retries for up to one second while the
     * lock is still this process's and unexpired. If the file stays busy that long, the lock is still
     * held for the rest of its term, and the next command renews it.
     */
    public function refresh(): bool
    {
        if ($this->lost) {
            return false;
        }

        // Taken before the store renews, so the recorded expiry never trails the store's.
        $renewedAt = Carbon::now()->getTimestamp();

        for ($attempt = 1; ! $this->lock->refresh(); $attempt++) {
            if (Carbon::now()->getTimestamp() >= $this->expiresAt || ! $this->lock->isOwnedByCurrentProcess()) {
                $this->lost = true;

                return false;
            }

            if ($attempt >= self::RenewAttempts) {
                return true;
            }

            Sleep::usleep(self::RenewRetryMicroseconds);
            $renewedAt = Carbon::now()->getTimestamp();
        }

        $this->expiresAt = $renewedAt + $this->seconds;

        return true;
    }

    public function name(): string
    {
        return $this->name;
    }

    private function held(): void
    {
        $this->lost = false;
        $this->expiresAt = Carbon::now()->getTimestamp() + $this->seconds;
        $this->locks->hold($this->name, $this);
    }
}
