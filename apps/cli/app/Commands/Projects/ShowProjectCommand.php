<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\PromptAborted;
use App\Support\NamedAppOptions;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Projects\ShowProjectRequest;
use Orbit\Sdk\Responses\Instances\InstancesResponse;
use Orbit\Sdk\Responses\Projects\ProjectResponse;

final class ShowProjectCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'project:show
        {project : Numeric project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show a project.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $projectId = $this->positiveId('project', 'Project', 'project.id_invalid');

        if ($projectId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $project = $this->sendWithProgress($connector, new ShowProjectRequest($projectId), ProjectResponse::class, ['Show Project', 'Fetching Project', 'Fetched Project'], dismiss: true);

        if (! $project instanceof ProjectResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($project->toArray());

            return self::SUCCESS;
        }

        $instances = $this->sendWithProgress($connector, new ListInstancesRequest, InstancesResponse::class, ['Instances', 'Fetching Instances', 'Fetched Instances'], dismiss: true);
        if (! $instances instanceof InstancesResponse) {
            return self::FAILURE;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Project: {$project->slug}", [
            'ID' => $project->id,
            'Name' => $project->name,
            'Type' => $project->type,
            'Repository' => $project->repositoryUrl,
            'Source access' => $project->sourceAccess,
            'Default branch' => $project->defaultBranch,
            ...NamedAppOptions::detailFields($project->apps),
            ...($project->taskCompute === null ? [] : ['Task compute' => $project->taskCompute]),
            'Task check' => $project->taskCheck,
            'Task workspace routed' => $project->taskWorkspaceRouted,
            ...($project->reviewAndMerge === null ? [] : ['Review and merge' => $project->reviewAndMerge, 'Merge check' => $project->mergeCheck]),
            ...($project->excludedNodes === null ? [] : [
                'Excluded nodes' => array_map(static fn (array $exclusion): string => $exclusion['node_name'], $project->excludedNodes) ?: null,
            ]),
        ]));

        $headers = ['ID', 'Name', 'Node', 'Domain', 'Status'];
        $rows = [];
        foreach ($instances->instances as $instance) {
            if ($instance->projectId !== $project->id) {
                continue;
            }
            $rows[$instance->id] = [(string) $instance->id, $instance->name, $instance->node->name ?? (string) $instance->nodeId, $instance->domain ?? '—', $instance->status];
        }

        if ($this->consoleMode()->mayPrompt && $rows !== []) {
            // The Project's instances are the selector: Enter shows the highlighted Instance.
            try {
                $selected = $this->commandPrompts()->selectEntity('Instances', $headers, $rows);
            } catch (PromptAborted) {
                return self::SUCCESS;
            }

            return $this->call('instance:show', ['instance' => (string) $selected]);
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table($headers, array_values($rows), 'No Instances.'));

        return self::SUCCESS;
    }
}
