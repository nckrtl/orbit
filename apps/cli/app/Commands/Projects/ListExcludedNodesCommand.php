<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Commands\Projects\Concerns\RendersDevelopmentNodeExclusions;
use App\Commands\Projects\Concerns\ResolvesDevelopmentNodeExclusions;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\ListProjectExcludedNodesRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionsResponse;

final class ListExcludedNodesCommand extends GatewayCommand
{
    use RendersDevelopmentNodeExclusions;
    use ResolvesDevelopmentNodeExclusions;

    #[\Override]
    protected $signature = 'project:excluded-node:list
        {--project= : Numeric Project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List the app-dev Nodes a Project cannot use for development.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $project = $this->option('project');
        if (! $this->validExclusionProjectInput($project)) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }

        $projectId = $this->resolveExclusionProjectId($connector, $project);
        if ($projectId === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListProjectExcludedNodesRequest($projectId),
            DevelopmentNodeExclusionsResponse::class,
            ['List exclusions', 'Listing exclusions', 'Listed exclusions'],
        );

        return $response instanceof DevelopmentNodeExclusionsResponse
            ? $this->renderExclusionList($response)
            : self::FAILURE;
    }
}
