<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Schedules\ListSchedulesRequest;
use Orbit\Sdk\Responses\Schedules\SchedulesResponse;

final class ListSchedulesCommand extends ScheduleCommand
{
    #[\Override]
    protected $signature = 'schedule:list
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List authorized Schedules.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send($connector, new ListSchedulesRequest, SchedulesResponse::class);

        if (! $response instanceof SchedulesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->schedules as $schedule) {
            $rows[] = [
                $schedule->id,
                "{$schedule->targetType}:{$schedule->targetId}",
                $schedule->name,
                $schedule->calendar,
                $schedule->desiredTimerState,
                $schedule->status,
                $schedule->lastRunStatus ?? 'never',
            ];
        }

        $this->table(
            ['UUID', 'Target', 'Name', 'Calendar', 'Desired timer', 'Lifecycle', 'Last run'],
            $rows,
        );
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
