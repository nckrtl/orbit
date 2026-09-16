<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\Schedules\ScheduleResponse;

abstract class ScheduleCommand extends GatewayCommand
{
    protected function renderSchedule(ScheduleResponse $schedule): void
    {
        if ($this->option('json') === true) {
            $this->writeJson($schedule->toArray());

            return;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Schedule [{$schedule->name}].", [
            'UUID' => $schedule->id,
            'Target' => "{$schedule->targetType}:{$schedule->targetId}",
            'Name' => $schedule->name,
            'Calendar' => $schedule->calendar,
            'Command' => $schedule->command,
            'Timeout seconds' => $schedule->timeoutSeconds,
            'Desired timer state' => $schedule->desiredTimerState,
            'Lifecycle state' => $schedule->status,
            'Failed step' => $schedule->failedStep,
            'Error code' => $schedule->errorCode,
            'Last run at' => $schedule->lastRunAt,
            'Last run status' => $schedule->lastRunStatus,
            'Request ID' => $schedule->requestId,
        ]));
    }
}
