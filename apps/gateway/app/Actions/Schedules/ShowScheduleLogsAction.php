<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Data\Schedules\ScheduleLogsData;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Schedule;
use SensitiveParameter;

final readonly class ShowScheduleLogsAction
{
    public function __construct(private ScheduleRuntimeManager $runtime) {}

    public function execute(#[SensitiveParameter] Schedule $schedule, int $lines = 100): ScheduleLogsData
    {
        if ($schedule->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                ScheduleErrorCode::StateInvalid->value,
                'The Schedule logs are unavailable in its current state.',
                409,
            );
        }

        return $this->runtime->logs($schedule, $lines);
    }
}
