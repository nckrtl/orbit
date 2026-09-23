<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\Projects\Concerns\RendersDevelopmentNodeExclusions;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Nodes\RemoveNodeExcludedProjectRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionResponse;

final class RemoveExcludedProjectCommand extends NodeCommand
{
    use RendersDevelopmentNodeExclusions;

    #[\Override]
    protected $signature = 'node:excluded-project:remove
        {project : Numeric Project ID}
        {--node= : Node ID or name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a Project from the development exclusions of an app-dev Node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $projectId = $this->positiveId('project', 'Project', 'app.id_invalid');
        $connector = $projectId === null ? null : $this->gatewayConnector($repository, $connectors);

        if ($projectId === null || $connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->option('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new RemoveNodeExcludedProjectRequest($nodeId, $projectId),
            DevelopmentNodeExclusionResponse::class,
            ['Remove exclusion', 'Removing exclusion', 'Removed exclusion'],
        );

        return $response instanceof DevelopmentNodeExclusionResponse
            ? $this->renderExclusion($response, 'remove')
            : self::FAILURE;
    }
}
