<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\DestroyProjectRequest;
use Orbit\Sdk\Requests\Projects\ShowProjectRequest;
use Orbit\Sdk\Responses\Projects\ProjectResponse;

final class DestroyProjectCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'project:destroy
        {project : Numeric project ID}
        {--yes : Confirm removal without prompting}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a project.';

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

        if ($this->option('yes') !== true) {
            $existing = $this->sendWithProgress(
                $connector,
                new ShowProjectRequest($projectId),
                ProjectResponse::class,
                ['Inspect Project', 'Inspecting Project', 'Inspected Project'],
            );

            if (! $existing instanceof ProjectResponse || ! $this->confirmAction(
                "Confirm Project removal [{$existing->slug}]?",
                'Project removal cancelled.',
            )) {
                return self::FAILURE;
            }
        }

        $project = $this->sendWithProgress($connector, new DestroyProjectRequest($projectId), ProjectResponse::class, ['Remove Project', 'Removing Project', 'Removed Project']);

        if (! $project instanceof ProjectResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($project->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Project [{$project->slug}] removed.");
        $this->writeHumanMessage("Request ID: {$project->requestId}");

        return self::SUCCESS;
    }
}
