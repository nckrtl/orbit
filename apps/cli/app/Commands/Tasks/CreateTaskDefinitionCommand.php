<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\CreateTaskDefinitionRequest;
use Orbit\Sdk\Responses\Tasks\TaskDefinitionResponse;

final class CreateTaskDefinitionCommand extends TaskDefinitionCommand
{
    #[\Override]
    protected $signature = 'tasks:definition:create
        {--project= : Numeric Project ID}
        {--definition= : JSON file of the whole definition}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a task definition from a JSON file.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $projectId = $this->idOption('project', 'Project', 'project');
        $document = $projectId === false ? false : $this->suppliedDefinitionFile($this->option('definition'));

        if ($projectId === false || $document === false) {
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

        $document ??= $this->promptDefinitionFile();

        if ($document === false) {
            return self::FAILURE;
        }

        $definition = $this->sendWithProgress(
            $connector,
            new CreateTaskDefinitionRequest($projectId, $document),
            TaskDefinitionResponse::class,
            ['Create task definition', 'Creating task definition', 'Created task definition'],
        );

        return $definition instanceof TaskDefinitionResponse ? $this->renderDefinition($definition) : self::FAILURE;
    }
}
