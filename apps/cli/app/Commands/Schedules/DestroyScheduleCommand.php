<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Commands\Concerns\RendersProjectRuntimeDefinitions;
use App\Commands\Concerns\SelectsProjectDefinitionTarget;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Projects\DestroyScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Schedules\DestroyScheduleRequest;
use Orbit\Sdk\Responses\Projects\ProjectRuntimeDefinitionResponse;

final class DestroyScheduleCommand extends ScheduleItemCommand
{
    use RendersProjectRuntimeDefinitions;
    use SelectsProjectDefinitionTarget;

    #[\Override]
    protected $signature = 'schedule:destroy
        {schedule : Schedule UUID or definition name}
        {--project= : Numeric Project ID}
        {--yes : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy one Schedule or Project Schedule definition through the Gateway.';

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
            $scheduleId = $this->scheduleId();

            if ($scheduleId === null) {
                return self::FAILURE;
            }

            if ($this->gatewayConnector($repository, $connectors) === null) {
                return self::FAILURE;
            }

            if (! $this->confirmAction(
                "Destroy Schedule [{$scheduleId}] and remove its timer artifacts?",
                'Schedule destruction cancelled.',
            )) {
                return self::FAILURE;
            }

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

        if (! $this->confirmAction(
            "Destroy Schedule definition [{$name}]?",
            'Schedule destruction cancelled.',
        )) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new DestroyScheduleDefinitionRequest($projectId, $name),
            ProjectRuntimeDefinitionResponse::class,
            $this->progressLabels(),
        );

        return $response instanceof ProjectRuntimeDefinitionResponse
            ? $this->renderDefinition($response, 'Schedule')
            : self::FAILURE;
    }

    protected function request(string $scheduleId): GatewayRequest
    {
        return new DestroyScheduleRequest($scheduleId);
    }

    protected function progressLabels(): array
    {
        return ['Destroy Schedule', 'Destroying Schedule', 'Destroyed Schedule'];
    }
}
