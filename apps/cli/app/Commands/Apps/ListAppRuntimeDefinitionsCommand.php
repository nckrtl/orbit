<?php

declare(strict_types=1);

namespace App\Commands\Apps;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Apps\ListProcessDefinitionsRequest;
use Orbit\Sdk\Requests\Apps\ListScheduleDefinitionsRequest;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionsResponse;

abstract class ListAppRuntimeDefinitionsCommand extends AppRuntimeDefinitionCommand
{
    /** @return 'process'|'schedule' */
    abstract protected function definitionKind(): string;

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $appId = $this->positiveId('app', 'App', 'app.id_invalid');

        if ($appId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send($connector, $this->request($appId), AppRuntimeDefinitionsResponse::class);

        return $response instanceof AppRuntimeDefinitionsResponse
            ? $this->renderDefinitions($response)
            : self::FAILURE;
    }

    private function request(int $appId): GatewayRequest
    {
        return match ($this->definitionKind()) {
            'process' => new ListProcessDefinitionsRequest($appId),
            'schedule' => new ListScheduleDefinitionsRequest($appId),
        };
    }
}
