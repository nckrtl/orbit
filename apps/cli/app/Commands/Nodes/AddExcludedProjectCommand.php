<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\Projects\Concerns\RendersDevelopmentNodeExclusions;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Nodes\AddNodeExcludedProjectRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionResponse;

final class AddExcludedProjectCommand extends NodeCommand
{
    use RendersDevelopmentNodeExclusions;

    #[\Override]
    protected $signature = 'node:excluded-project:add
        {project : Numeric Project ID}
        {--node= : Node ID or name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Exclude a Project from development placement on an app-dev Node.';

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
            new AddNodeExcludedProjectRequest($nodeId, $projectId),
            DevelopmentNodeExclusionResponse::class,
            ['Exclude Project', 'Excluding Project', 'Excluded Project'],
        );

        return $response instanceof DevelopmentNodeExclusionResponse
            ? $this->renderExclusion($response, 'add')
            : self::FAILURE;
    }
}
