<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Schedules\DestroyScheduleRequest;

final class DestroyScheduleCommand extends ScheduleItemCommand
{
    #[\Override]
    protected $signature = 'schedule:destroy
        {schedule : Schedule UUID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy one Schedule through the Gateway.';

    protected function request(string $scheduleId): GatewayRequest
    {
        return new DestroyScheduleRequest($scheduleId);
    }
}
