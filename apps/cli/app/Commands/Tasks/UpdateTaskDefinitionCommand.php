<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\UpdateTaskDefinitionRequest;
use Orbit\Sdk\Responses\Tasks\TaskDefinitionResponse;

final class UpdateTaskDefinitionCommand extends TaskDefinitionCommand
{
    #[\Override]
    protected $signature = 'tasks:definition:update
        {name? : Definition name}
        {--project= : Numeric Project ID}
        {--definition= : JSON file that replaces the whole definition}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Replace one task definition from a JSON file.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $projectId = $this->idOption('project', 'Project', 'project');
        $name = $projectId === false ? false : $this->definitionName($this->argument('name'));
        $document = $name === false ? false : $this->suppliedDefinitionFile($this->option('definition'));

        if ($projectId === false || $name === false || $document === false) {
            return self::FAILURE;
        }

        if (is_string($name) && is_string($document) && ! $this->definitionNameMatches($document, $name)) {
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

        $document ??= $this->promptDefinitionFile();

        if ($document === false || ! $this->definitionNameMatches($document, $name)) {
            return self::FAILURE;
        }

        $definition = $this->sendWithProgress(
            $connector,
            new UpdateTaskDefinitionRequest($projectId, $name, $document),
            TaskDefinitionResponse::class,
            ['Replace task definition', 'Replacing task definition', 'Replaced task definition'],
        );

        return $definition instanceof TaskDefinitionResponse ? $this->renderDefinition($definition) : self::FAILURE;
    }
}
