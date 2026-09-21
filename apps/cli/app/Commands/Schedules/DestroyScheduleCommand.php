<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Commands\Concerns\RendersAppRuntimeDefinitions;
use App\Commands\Concerns\SelectsAppDefinitionTarget;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Apps\DestroyScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Schedules\DestroyScheduleRequest;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionResponse;

final class DestroyScheduleCommand extends ScheduleItemCommand
{
    use RendersAppRuntimeDefinitions;
    use SelectsAppDefinitionTarget;

    #[\Override]
    protected $signature = 'schedule:destroy
        {schedule : Schedule UUID or definition name}
        {--project= : Numeric Project ID}
        {--app= : Numeric Project ID (compatibility)}
        {--yes : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy one Schedule or Project Schedule definition through the Gateway.';

    #[\Override]
    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $appId = $this->appIdOption();

        if ($appId === false) {
            return self::FAILURE;
        }

        if ($appId === null) {
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
            new DestroyScheduleDefinitionRequest($appId, $name),
            AppRuntimeDefinitionResponse::class,
            $this->progressLabels(),
        );

        return $response instanceof AppRuntimeDefinitionResponse
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
