<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Commands\Concerns\RendersProjectRuntimeDefinitions;
use App\Commands\Concerns\SelectsProjectDefinitionTarget;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Projects\ListScheduleDefinitionsRequest;
use Orbit\Sdk\Requests\Schedules\ListSchedulesRequest;
use Orbit\Sdk\Responses\Projects\ProjectRuntimeDefinitionsResponse;
use Orbit\Sdk\Responses\Schedules\SchedulesResponse;

final class ListSchedulesCommand extends ScheduleCommand
{
    use RendersProjectRuntimeDefinitions;
    use SelectsProjectDefinitionTarget;

    #[\Override]
    protected $signature = 'schedule:list
        {--project= : Numeric Project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List authorized Schedules or Project Schedule definitions.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $projectId = $this->projectIdOption();

        if ($projectId === false) {
            return self::FAILURE;
        }

        if ($projectId !== null) {
            $response = $this->sendWithProgress(
                $connector,
                new ListScheduleDefinitionsRequest($projectId),
                ProjectRuntimeDefinitionsResponse::class,
                ['List Schedule definitions', 'Loading Schedule definitions', 'Loaded Schedule definitions'],
            );

            return $response instanceof ProjectRuntimeDefinitionsResponse
                ? $this->renderDefinitions($response)
                : self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListSchedulesRequest,
            SchedulesResponse::class,
            ['List Schedules', 'Loading Schedules', 'Loaded Schedules'],
        );

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

        if ($rows === []) {
            $this->writeHumanMessage('No matching records found.');
            $this->writeHumanMessage("Request ID: {$response->requestId}");

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['UUID', 'Target', 'Name', 'Calendar', 'Desired timer', 'Lifecycle', 'Last run'],
            $rows,
        ));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
