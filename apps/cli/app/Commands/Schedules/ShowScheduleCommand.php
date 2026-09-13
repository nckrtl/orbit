<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Schedules\ShowScheduleRequest;

final class ShowScheduleCommand extends ScheduleItemCommand
{
    #[\Override]
    protected $signature = 'schedule:show
        {schedule : Schedule UUID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one authorized Schedule.';

    protected function request(string $scheduleId): GatewayRequest
    {
        return new ShowScheduleRequest($scheduleId);
    }
}
