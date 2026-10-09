<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\PromptAborted;
use Orbit\Sdk\Requests\Projects\ListProjectsRequest;
use Orbit\Sdk\Responses\Projects\ProjectAppResponse;
use Orbit\Sdk\Responses\Projects\ProjectsResponse;

final class ListProjectsCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'project:list
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List projects.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress($connector, new ListProjectsRequest, ProjectsResponse::class, ['List Projects', 'Fetching Projects', 'Fetched Projects'], dismiss: true);

        if (! $response instanceof ProjectsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $headers = ['ID', 'Name', 'Slug', 'Type', 'Repository', 'Default branch', 'Apps'];
        $rows = [];
        foreach ($response->projects as $project) {
            $rows[$project->id] = [
                (string) $project->id,
                $project->name,
                $project->slug,
                $project->type,
                $project->repositoryUrl,
                $project->defaultBranch ?? '—',
                implode(', ', array_map(static fn (ProjectAppResponse $app): string => $app->name, $project->apps)) ?: '—',
            ];
        }

        if ($this->consoleMode()->mayPrompt && $rows !== []) {
            // In a terminal the list is the selector: Enter shows the highlighted Project.
            try {
                $selected = $this->commandPrompts()->selectEntity('Projects', $headers, $rows);
            } catch (PromptAborted) {
                return self::SUCCESS;
            }

            return $this->call('project:show', ['project' => (string) $selected]);
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table($headers, array_values($rows), 'No Projects found.'));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
