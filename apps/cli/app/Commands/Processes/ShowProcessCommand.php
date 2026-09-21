<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Commands\Concerns\RendersAppRuntimeDefinitions;
use App\Commands\Concerns\SelectsAppDefinitionTarget;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Apps\ShowProcessDefinitionRequest;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionResponse;

final class ShowProcessCommand extends ProcessCommand
{
    use RendersAppRuntimeDefinitions;
    use SelectsAppDefinitionTarget;

    #[\Override]
    protected $signature = 'process:show
        {name : Process definition name}
        {--project= : Numeric Project ID}
        {--app= : Numeric Project ID (compatibility)}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one App process definition.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $appId = $this->appIdOption();
        $name = $this->stringArgument('name', 'Process definition name', 'process.name_required');

        if ($appId === false) {
            return self::FAILURE;
        }

        if ($appId === null) {
            return $this->renderGatewayFailure(
                'process.target_invalid',
                'The --project or --app option is required.',
            );
        }

        if ($name === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ShowProcessDefinitionRequest($appId, $name),
            AppRuntimeDefinitionResponse::class,
            ['Show Process definition', 'Loading Process definition', 'Loaded Process definition'],
        );

        return $response instanceof AppRuntimeDefinitionResponse
            ? $this->renderDefinition($response, 'Process')
            : self::FAILURE;
    }
}
