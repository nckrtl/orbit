<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

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
    public function __construct(private ScheduleRuntimeManager $runtime) {}

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

        if ($schedule->desired_timer_state !== DesiredTimerState::Enabled) {
            $schedule->update(['desired_timer_state' => DesiredTimerState::Enabled]);
        }

        return $schedule->refresh();
    }
}
