<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Data\Schedules\ScheduleData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Schedule;
use SensitiveParameter;

final readonly class ActivateScheduleAction
{
    public function __construct(
        private ScheduleRuntimeManager $runtime,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(#[SensitiveParameter] Schedule $schedule): Schedule
    {
        if ($schedule->target_type !== AppInstance::class) {
            throw new ResourceOperationException(
                ScheduleErrorCode::TargetInvalid->value,
                'Only an AppInstance Schedule can be activated.',
                422,
            );
        }

        if ($schedule->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                ScheduleErrorCode::StateInvalid->value,
                'The Schedule cannot be activated in its current state.',
                409,
            );
        }

        $this->runtime->activate($schedule);
        $changed = $schedule->desired_timer_state !== DesiredTimerState::Enabled;

        if ($changed) {
            $schedule->update(['desired_timer_state' => DesiredTimerState::Enabled]);
        }

        $result = $schedule->refresh();

        if ($changed) {
            ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
                RecordEventType::ScheduleUpdated,
                $result->id,
                ScheduleData::fromModel($result)->toArray(),
            );
        }

        return $result;
    }
}
