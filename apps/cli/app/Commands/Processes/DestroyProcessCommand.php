<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Commands\Concerns\RendersAppRuntimeDefinitions;
use App\Commands\Concerns\SelectsAppDefinitionTarget;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Apps\DestroyProcessDefinitionRequest;
use Orbit\Sdk\Requests\Processes\DestroyProcessRequest;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionResponse;

final class DestroyProcessCommand extends ProcessActionCommand
{
    use RendersAppRuntimeDefinitions;
    use SelectsAppDefinitionTarget;

    #[\Override]
    protected $signature = 'process:destroy
        {process : Process ID or definition name}
        {--project= : Numeric Project ID}
        {--app= : Numeric Project ID (compatibility)}
        {--yes : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy one process or Project process definition.';

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
            $processId = $this->positiveId('process', 'Process', 'process.id_invalid');

            if ($processId === null) {
                return self::FAILURE;
            }

            $connector = $this->gatewayConnector($repository, $connectors);

            if ($connector === null) {
                return self::FAILURE;
            }

            if (! $this->confirmAction(
                "Destroy Process [{$processId}] and remove its runtime artifacts?",
                'Process destruction cancelled.',
            )) {
                return self::FAILURE;
            }

            return $this->handleProcess($connector, $processId);
        }

        $name = $this->stringArgument('process', 'Process definition name', 'process.name_required');

        if ($name === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if (! $this->confirmAction(
            "Destroy Process definition [{$name}]?",
            'Process destruction cancelled.',
        )) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new DestroyProcessDefinitionRequest($appId, $name),
            AppRuntimeDefinitionResponse::class,
            $this->progressLabels(),
        );

        return $response instanceof AppRuntimeDefinitionResponse
            ? $this->renderDefinition($response, 'Process')
            : self::FAILURE;
    }

    protected function request(int $processId): GatewayRequest
    {
        return new DestroyProcessRequest($processId);
    }

    protected function pastTense(): string
    {
        return 'removed';
    }

    protected function progressLabels(): array
    {
        return ['Destroy Process', 'Destroying Process', 'Destroyed Process'];
    }
}
