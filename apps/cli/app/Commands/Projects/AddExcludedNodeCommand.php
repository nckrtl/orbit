<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Commands\Projects\Concerns\RendersDevelopmentNodeExclusions;
use App\Commands\Projects\Concerns\ResolvesDevelopmentNodeExclusions;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\AddProjectExcludedNodeRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionResponse;

final class AddExcludedNodeCommand extends GatewayCommand
{
    use RendersDevelopmentNodeExclusions;
    use ResolvesDevelopmentNodeExclusions;

    #[\Override]
    protected $signature = 'project:excluded-node:add
        {node? : Node ID or name}
        {--project= : Numeric Project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Exclude an app-dev Node from development placement for a Project.';

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

        $nodeId = $this->resolveExclusionNodeId($connector, $this->argument('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new AddProjectExcludedNodeRequest($projectId, $nodeId),
            DevelopmentNodeExclusionResponse::class,
            ['Exclude Node', 'Excluding Node', 'Excluded Node'],
        );

        return $response instanceof DevelopmentNodeExclusionResponse
            ? $this->renderExclusion($response, 'add')
            : self::FAILURE;
    }
}
