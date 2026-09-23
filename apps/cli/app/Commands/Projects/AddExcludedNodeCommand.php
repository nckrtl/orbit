<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Commands\Projects\Concerns\RendersDevelopmentNodeExclusions;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\AddProjectExcludedNodeRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionResponse;

final class AddExcludedNodeCommand extends GatewayCommand
{
    use RendersDevelopmentNodeExclusions;

    #[\Override]
    protected $signature = 'project:excluded-node:add
        {node : Node ID or name}
        {--project= : Numeric Project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Exclude an app-dev Node from development placement for a Project.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $projectId = $this->positiveProjectId();
        $connector = $projectId === null ? null : $this->gatewayConnector($repository, $connectors);

        if ($projectId === null || $connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->argument('node'));

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

    private function positiveProjectId(): ?int
    {
        $id = filter_var($this->option('project'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($id)) {
            $this->renderGatewayFailure('app.id_invalid', 'Project ID must be a positive integer.');

            return null;
        }

        return $id;
    }
}
