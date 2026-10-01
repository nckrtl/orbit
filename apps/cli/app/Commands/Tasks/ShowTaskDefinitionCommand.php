<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\ShowTaskDefinitionRequest;
use Orbit\Sdk\Responses\Tasks\TaskDefinitionResponse;

final class ShowTaskDefinitionCommand extends TaskDefinitionCommand
{
    #[\Override]
    protected $signature = 'tasks:definition:show
        {name? : Definition name}
        {--project= : Numeric Project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one task definition.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $projectId = $this->idOption('project', 'Project', 'project');
        $name = $projectId === false ? false : $this->definitionName($this->argument('name'));

        if ($projectId === false || $name === false) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $projectId ??= $this->selectProject($connector);

        if ($projectId === null) {
            return self::FAILURE;
        }

        $name ??= $this->selectDefinition($connector, $projectId);

        if ($name === null) {
            return self::FAILURE;
        }

        $definition = $this->sendWithProgress(
            $connector,
            new ShowTaskDefinitionRequest($projectId, $name),
            TaskDefinitionResponse::class,
            ['Show task definition', 'Loading task definition', 'Loaded task definition'],
        );

        return $definition instanceof TaskDefinitionResponse ? $this->renderDefinition($definition) : self::FAILURE;
    }
}
