<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Commands\Concerns\RendersProjectRuntimeDefinitions;
use App\Commands\Concerns\SelectsProjectDefinitionTarget;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\ShowProcessDefinitionRequest;
use Orbit\Sdk\Responses\Projects\ProjectRuntimeDefinitionResponse;

final class ShowProcessCommand extends ProcessCommand
{
    use RendersProjectRuntimeDefinitions;
    use SelectsProjectDefinitionTarget;

    #[\Override]
    protected $signature = 'process:show
        {name : Process definition name}
        {--project= : Numeric Project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one Project process definition.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $projectId = $this->projectIdOption();
        $name = $this->stringArgument('name', 'Process definition name', 'process.name_required');

        if ($projectId === false) {
            return self::FAILURE;
        }

        if ($projectId === null) {
            return $this->renderGatewayFailure(
                'process.target_invalid',
                'The --project option is required.',
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
            new ShowProcessDefinitionRequest($projectId, $name),
            ProjectRuntimeDefinitionResponse::class,
            ['Show Process definition', 'Loading Process definition', 'Loaded Process definition'],
        );

        return $response instanceof ProjectRuntimeDefinitionResponse
            ? $this->renderDefinition($response, 'Process')
            : self::FAILURE;
    }
}
