<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Schedules\ActivateScheduleRequest;

final class ActivateScheduleCommand extends ScheduleItemCommand
{
    #[\Override]
    protected $signature = 'schedule:activate
        {schedule : Schedule UUID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Enable and start one installed AppInstance Schedule timer.';

    protected function request(string $scheduleId): GatewayRequest
    {
        return new ActivateScheduleRequest($scheduleId);
    }
}
