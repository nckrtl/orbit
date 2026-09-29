<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Commands\Concerns\RendersProjectRuntimeDefinitions;
use App\Commands\Concerns\SelectsProjectDefinitionTarget;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Processes\DestroyProcessRequest;
use Orbit\Sdk\Requests\Projects\DestroyProcessDefinitionRequest;
use Orbit\Sdk\Responses\Projects\ProjectRuntimeDefinitionResponse;

final class DestroyProcessCommand extends ProcessActionCommand
{
    use RendersProjectRuntimeDefinitions;
    use SelectsProjectDefinitionTarget;

    #[\Override]
    protected $signature = 'process:destroy
        {process : Process ID or definition name}
        {--project= : Numeric Project ID}
        {--yes : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy one process or Project process definition.';

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
            $processId = $this->positiveId('process', 'Process', 'process.id_invalid');

            if ($processId === null) {
                return self::FAILURE;
            }

            if ($this->gatewayConnector($repository, $connectors) === null) {
                return self::FAILURE;
            }

            if (! $this->confirmAction(
                "Destroy Process [{$processId}] and remove its runtime artifacts?",
                'Process destruction cancelled.',
            )) {
                return self::FAILURE;
            }

            return parent::handle($repository, $connectors);
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
            new DestroyProcessDefinitionRequest($projectId, $name),
            ProjectRuntimeDefinitionResponse::class,
            $this->progressLabels(),
        );

        return $response instanceof ProjectRuntimeDefinitionResponse
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
