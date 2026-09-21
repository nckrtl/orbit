<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Commands\Concerns\RendersAppRuntimeDefinitions;
use App\Commands\Concerns\SelectsAppDefinitionTarget;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Apps\ShowScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Schedules\ShowScheduleRequest;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionResponse;

final class ShowScheduleCommand extends ScheduleItemCommand
{
    use RendersAppRuntimeDefinitions;
    use SelectsAppDefinitionTarget;

    #[\Override]
    protected $signature = 'schedule:show
        {schedule : Schedule UUID or definition name}
        {--project= : Numeric Project ID}
        {--app= : Numeric Project ID (compatibility)}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one authorized Schedule or Project Schedule definition.';

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
            new ShowScheduleDefinitionRequest($appId, $name),
            AppRuntimeDefinitionResponse::class,
            ['Show Schedule definition', 'Loading Schedule definition', 'Loaded Schedule definition'],
        );

        return $response instanceof AppRuntimeDefinitionResponse
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
