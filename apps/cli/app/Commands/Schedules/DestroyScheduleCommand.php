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
        {--app= : Numeric App ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy one Schedule or App Schedule definition through the Gateway.';

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

        $response = $this->send(
            $connector,
            new DestroyScheduleDefinitionRequest($appId, $name),
            AppRuntimeDefinitionResponse::class,
        );

        return $response instanceof AppRuntimeDefinitionResponse
            ? $this->renderDefinition($response, 'Schedule')
            : self::FAILURE;
    }

    protected function request(string $scheduleId): GatewayRequest
    {
        return new DestroyScheduleRequest($scheduleId);
    }
}
