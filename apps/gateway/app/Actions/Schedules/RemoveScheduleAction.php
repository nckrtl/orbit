<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Schedule;
use SensitiveParameter;

final readonly class RemoveScheduleAction
{
    public function __construct(private ScheduleRuntimeManager $runtime) {}

    public function execute(#[SensitiveParameter] Schedule $schedule, bool $cascade = false): Schedule
    {
        $schedule->update([
            'status' => LifecycleStatus::Removing,
            'failed_step' => null,
            'error_code' => null,
        ]);

        try {
            $complete = $this->runtime->remove($schedule, $cascade);
        } catch (ScheduleOperationException $exception) {
            $schedule->update([
                'failed_step' => $exception->step,
                'error_code' => $exception->errorCode,
            ]);

            throw $exception;
        } catch (ResourceOperationException $exception) {
            $schedule->update([
                'failed_step' => 'resolve-target',
                'error_code' => $exception->errorCode,
            ]);

            throw $exception;
        }

        if ($complete) {
            $schedule->delete();
        }

        return $schedule;
    }
}
