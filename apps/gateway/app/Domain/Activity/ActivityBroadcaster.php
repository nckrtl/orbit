<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Models\Activity;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Broadcasts short Activity notices on the orbit channel (ADR 0159). A notice carries the list
 * columns and never `properties`, so it stays under Reverb's message limit. Rows whose command
 * starts with `activity:` are omitted. A broadcast failure is logged by the record broadcaster
 * and does not fail the write.
 */
final readonly class ActivityBroadcaster
{
    /** Columns a notice carries, other than id and occurred_at. A write that changes none of them is not broadcast. */
    private const array NoticeColumns = [
        'request_id',
        'command',
        'status',
        'caller_node_id',
        'target_node_id',
        'error_code',
        'duration_ms',
    ];

    public function __construct(private RecordEventBroadcaster $broadcaster) {}

    public function created(Activity $activity): void
    {
        $this->send(RecordEventType::ActivityCreated, $activity);
    }

    /**
     * @param  list<string>|null  $changedColumns  Null when the caller already changed a notice column, such as the
     *                                             interrupted-Activity sweep. A write that changes only `properties`
     *                                             passes those columns and is not broadcast.
     */
    public function updated(Activity $activity, ?array $changedColumns = null): void
    {
        if ($changedColumns !== null && ! self::changesNotice($changedColumns)) {
            return;
        }

        $this->send(RecordEventType::ActivityUpdated, $activity);
    }

    /**
     * @param  list<string>  $columns
     */
    private static function changesNotice(array $columns): bool
    {
        return array_intersect($columns, self::NoticeColumns) !== [];
    }

    private function send(RecordEventType $type, Activity $activity): void
    {
        if (str_starts_with($activity->command, 'activity:')) {
            return;
        }

        $id = (int) $activity->getKey();
        $notice = self::notice($activity);
        $dispatch = function () use ($type, $id, $notice): void {
            $this->broadcaster->broadcast($type, $id, $notice);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }

    /**
     * @return array{
     *     id: int,
     *     request_id: string,
     *     command: string,
     *     status: string,
     *     caller_node_id: int|null,
     *     target_node_id: int|null,
     *     error_code: string|null,
     *     duration_ms: int|null,
     *     occurred_at: string
     * }
     */
    private static function notice(Activity $activity): array
    {
        $createdAt = $activity->created_at;

        return [
            'id' => (int) $activity->getKey(),
            'request_id' => $activity->request_id,
            'command' => $activity->command,
            'status' => $activity->status,
            'caller_node_id' => self::nullableInt($activity->getAttribute('caller_node_id')),
            'target_node_id' => self::nullableInt($activity->getAttribute('target_node_id')),
            'error_code' => $activity->error_code,
            'duration_ms' => $activity->duration_ms,
            'occurred_at' => $createdAt instanceof DateTimeInterface ? $createdAt->format(DateTimeInterface::ATOM) : '',
        ];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
