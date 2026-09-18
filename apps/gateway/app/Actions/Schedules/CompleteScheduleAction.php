<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Data\Schedules\ScheduleData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleRunStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;

final readonly class CompleteScheduleAction
{
    public function __construct(private ?RecordEventBroadcaster $broadcaster = null) {}

    public function execute(string $scheduleId, ScheduleRunStatus $status, Node $caller): ?Schedule
    {
        $schedule = DB::transaction(function () use ($scheduleId, $status, $caller): ?Schedule {
            $schedule = Schedule::query()->lockForUpdate()->find($scheduleId);

            if (! $schedule instanceof Schedule) {
                return null;
            }

            if ($schedule->host_node_id !== $caller->id) {
                throw new ResourceOperationException(
                    ScheduleErrorCode::TargetInvalid->value,
                    'The completion caller is not the Schedule host Node.',
                    403,
                );
            }

            if ($schedule->status === LifecycleStatus::Removing) {
                return $schedule;
            }

            $schedule->update([
                'last_run_at' => now(),
                'last_run_status' => $status,
            ]);

            return $schedule->refresh();
        });

        if ($schedule instanceof Schedule && $schedule->status !== LifecycleStatus::Removing) {
            ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
                RecordEventType::ScheduleUpdated,
                $schedule->id,
                ScheduleData::fromModel($schedule)->toArray(),
            );
        }

        return $schedule;
    }
}
