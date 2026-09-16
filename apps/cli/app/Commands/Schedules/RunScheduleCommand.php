<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Schedules\RunScheduleRequest;

final class RunScheduleCommand extends ScheduleItemCommand
{
    #[\Override]
    protected $signature = 'schedule:run
        {schedule : Schedule UUID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Run one Schedule without changing its timer state.';

    protected function request(string $scheduleId): GatewayRequest
    {
        return new RunScheduleRequest($scheduleId);
    }

    protected function progressLabels(): array
    {
        return ['Run Schedule', 'Running Schedule', 'Ran Schedule'];
    }
}
