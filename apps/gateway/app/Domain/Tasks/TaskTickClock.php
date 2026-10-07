<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Remembers when a `tasks:tick` last took the tick lock and started its work, so an operator or a release smoke
 * test can tell a running scheduler from a stopped one. The value lives in the Gateway cache store, which every
 * Gateway process shares; clearing the cache only forgets it until the next tick.
 *
 * The record is an observation, not part of the tick: a cache that cannot be written or read is reported and the
 * tick goes on, and the status reports no last tick.
 */
final readonly class TaskTickClock
{
    private const string Key = 'orbit:tasks:tick:started_at';

    public function record(): void
    {
        try {
            Cache::forever(self::Key, now()->toISOString());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function lastStartedAt(): ?string
    {
        try {
            $value = Cache::get(self::Key);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return is_string($value) ? $value : null;
    }
}
