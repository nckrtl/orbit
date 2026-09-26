<?php

declare(strict_types=1);

namespace App\Actions\Activities;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * Ends the Activity rows of requests that were killed before they recorded an outcome (ADR 0158).
 *
 * PHP-FPM ends every Gateway request after 600 seconds, so a row still `running` after
 * `orbit.activity_interrupted_after` seconds can never finish. Each run ends at most BATCH rows,
 * and a row that finished in the meantime is left as it is.
 */
final readonly class FinalizeInterruptedActivitiesAction
{
    public const string ERROR_CODE = 'activity.interrupted';

    public const int BATCH = 500;

    public function execute(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $cutoff = $now->copy()->subSeconds(Config::integer('orbit.activity_interrupted_after'));

        $ids = Activity::query()
            ->where('status', 'running')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return self::finalize(Activity::query()->whereKey($ids->all()), $now);
    }

    /**
     * Marks the given rows interrupted when they are still running.
     *
     * @param  Builder<Activity>  $query
     */
    public static function finalize(Builder $query, Carbon $now): int
    {
        return $query->where('status', 'running')->update([
            'status' => 'failed',
            'error_code' => self::ERROR_CODE,
            'updated_at' => $now,
        ]);
    }
}
