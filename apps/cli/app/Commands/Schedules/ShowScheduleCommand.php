<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Commands\Concerns\RendersProjectRuntimeDefinitions;
use App\Commands\Concerns\SelectsProjectDefinitionTarget;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Projects\ShowScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Schedules\ShowScheduleRequest;
use Orbit\Sdk\Responses\Projects\ProjectRuntimeDefinitionResponse;

final class ShowScheduleCommand extends ScheduleItemCommand
{
    use RendersProjectRuntimeDefinitions;
    use SelectsProjectDefinitionTarget;

    #[\Override]
    protected $signature = 'schedule:show
        {schedule : Schedule UUID or definition name}
        {--project= : Numeric Project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one authorized Schedule or Project Schedule definition.';

    #[\Override]
    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $projectId = $this->projectIdOption();

        if ($projectId === false) {
            return self::FAILURE;
        }

        if ($projectId === null) {
            return parent::handle($repository, $connectors);
        }

        $name = $this->stringArgument('schedule', 'Schedule definition name', 'schedule.name_required');

        if ($name === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ShowScheduleDefinitionRequest($projectId, $name),
            ProjectRuntimeDefinitionResponse::class,
            ['Show Schedule definition', 'Loading Schedule definition', 'Loaded Schedule definition'],
        );

        return $response instanceof ProjectRuntimeDefinitionResponse
            ? $this->renderDefinition($response, 'Schedule')
            : self::FAILURE;
    }

    protected function request(string $scheduleId): GatewayRequest
    {
        return new ShowScheduleRequest($scheduleId);
    }

    protected function progressLabels(): array
    {
        return ['Show Schedule', 'Loading Schedule', 'Loaded Schedule'];
    }
}
