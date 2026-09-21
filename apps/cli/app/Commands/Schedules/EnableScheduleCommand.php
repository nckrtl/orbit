<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Schedules\EnableScheduleRequest;

final class EnableScheduleCommand extends ScheduleItemCommand
{
    #[\Override]
    protected $signature = 'schedule:enable
        {schedule : Schedule UUID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Enable and start one installed Instance Schedule timer.';

    protected function request(string $scheduleId): GatewayRequest
    {
        return new EnableScheduleRequest($scheduleId);
    }

    protected function progressLabels(): array
    {
        return ['Enable Schedule', 'Enabling Schedule', 'Enabled Schedule'];
    }
}
