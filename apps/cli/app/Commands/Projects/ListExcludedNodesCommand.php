<?php

declare(strict_types=1);

namespace App\Commands\Projects;

use App\Commands\GatewayCommand;
use App\Commands\Projects\Concerns\RendersDevelopmentNodeExclusions;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Projects\ListProjectExcludedNodesRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionsResponse;

final class ListExcludedNodesCommand extends GatewayCommand
{
    use RendersDevelopmentNodeExclusions;

    #[\Override]
    protected $signature = 'project:excluded-node:list
        {--project= : Numeric Project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List the app-dev Nodes a Project cannot use for development.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $projectId = $this->positiveProjectId();
        $connector = $projectId === null ? null : $this->gatewayConnector($repository, $connectors);

        if ($projectId === null || $connector === null) {
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
