<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Tasks\Concerns\ConfirmsTaskChanges;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\DestroyTaskDefinitionRequest;
use Orbit\Sdk\Responses\Tasks\TaskDefinitionResponse;

final class DestroyTaskDefinitionCommand extends TaskDefinitionCommand
{
    use ConfirmsTaskChanges;

    #[\Override]
    protected $signature = 'tasks:definition:destroy
        {name? : Definition name}
        {--project= : Numeric Project ID}
        {--yes : Confirm without prompting}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Delete one task definition.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $projectId = $this->idOption('project', 'Project', 'project');
        $name = $projectId === false ? false : $this->definitionName($this->argument('name'));

        if ($projectId === false || $name === false) {
            return self::FAILURE;
        }

        // A named definition is confirmed before any request. A prompt that still has to choose one confirms after.
        $confirmed = is_int($projectId) && is_string($name);

        if ($confirmed && ! $this->confirmDefinition($projectId, $name)) {
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

        if ($name === null || (! $confirmed && ! $this->confirmDefinition($projectId, $name))) {
            return self::FAILURE;
        }

        $definition = $this->sendWithProgress(
            $connector,
            new DestroyTaskDefinitionRequest($projectId, $name),
            TaskDefinitionResponse::class,
            ['Destroy task definition', 'Destroying task definition', 'Destroyed task definition'],
        );

        if (! $definition instanceof TaskDefinitionResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($definition->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Task definition [{$definition->name}] destroyed.");
        $this->writeHumanMessage("Request ID: {$definition->requestId}");

        return self::SUCCESS;
    }

    private function confirmDefinition(int $projectId, string $name): bool
    {
        return $this->consent(
            fn (): string => "Destroy task definition {$name} of Project {$projectId}?",
            "Task definition [{$name}] was not destroyed.",
        );
    }
}
