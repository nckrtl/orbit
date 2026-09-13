<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Schedules\RemoveScheduleRequest;

final class RemoveScheduleCommand extends ScheduleItemCommand
{
    #[\Override]
    protected $signature = 'schedule:remove
        {schedule : Schedule UUID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one Schedule through the Gateway.';

    protected function request(string $scheduleId): GatewayRequest
    {
        return new RemoveScheduleRequest($scheduleId);
    }
}
