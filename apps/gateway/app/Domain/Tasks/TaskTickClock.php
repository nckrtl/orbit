<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Illuminate\Support\Facades\Cache;

/**
 * Remembers when a `tasks:tick` last took the tick lock and started its work, so an operator or a release smoke
 * test can tell a running scheduler from a stopped one. The value lives in the Gateway cache store, which every
 * Gateway process shares; clearing the cache only forgets it until the next tick.
 */
final readonly class TaskTickClock
{
    private const string Key = 'orbit:tasks:tick:started_at';

    public function record(): void
    {
        Cache::forever(self::Key, now()->toISOString());
    }

    public function lastStartedAt(): ?string
    {
        $value = Cache::get(self::Key);

        return is_string($value) ? $value : null;
    }
}
