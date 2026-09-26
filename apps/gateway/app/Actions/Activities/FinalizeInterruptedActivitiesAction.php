<?php

declare(strict_types=1);

namespace App\Actions\Activities;

use App\Domain\Activity\ActivityBroadcaster;
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
     * Marks the given rows interrupted when they are still running, then broadcasts one
     * `activity.updated` notice for each row this call ended. The write is a query-builder update,
     * which fires no model events, so the notice is explicit. It is sent after the outcome is saved.
     * A broadcast failure is logged and leaves that outcome in place.
     *
     * @param  Builder<Activity>  $query
     */
    public static function finalize(Builder $query, Carbon $now): int
    {
        $ids = (clone $query)->where('status', 'running')->orderBy('id')->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $updated = (clone $query)->where('status', 'running')->whereKey($ids->all())->update([
            'status' => 'failed',
            'error_code' => self::ERROR_CODE,
            'updated_at' => $now,
        ]);

        if ($updated === 0) {
            return 0;
        }

        // The notice never carries `properties`, and a swept row's properties can be large.
        $ended = Activity::query()
            ->whereKey($ids->all())
            ->where('status', 'failed')
            ->where('error_code', self::ERROR_CODE)
            ->orderBy('id')
            ->get([
                'id',
                'request_id',
                'command',
                'status',
                'caller_node_id',
                'target_node_id',
                'error_code',
                'duration_ms',
                'created_at',
            ]);
        $broadcaster = app(ActivityBroadcaster::class);

        foreach ($ended as $activity) {
            $broadcaster->updated($activity);
        }

        return $updated;
    }
}
