<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Schedule;
use SensitiveParameter;

final readonly class RunScheduleAction
{
    public function __construct(private ScheduleRuntimeManager $runtime) {}

    public function execute(#[SensitiveParameter] Schedule $schedule): Schedule
    {
        if ($schedule->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                ScheduleErrorCode::StateInvalid->value,
                'The Schedule cannot run in its current state.',
                409,
            );
        }

        $this->runtime->run($schedule);

        return $schedule->refresh();
    }
}
