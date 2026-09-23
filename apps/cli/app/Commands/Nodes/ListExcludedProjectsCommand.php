<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\Projects\Concerns\RendersDevelopmentNodeExclusions;
use App\Commands\Projects\Concerns\ResolvesDevelopmentNodeExclusions;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Nodes\ListNodeExcludedProjectsRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionsResponse;

final class ListExcludedProjectsCommand extends NodeCommand
{
    use RendersDevelopmentNodeExclusions;
    use ResolvesDevelopmentNodeExclusions;

    #[\Override]
    protected $signature = 'node:excluded-project:list
        {--node= : Node ID or name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List the Projects that cannot use an app-dev Node for development.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveExclusionNodeId($connector, $this->option('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListNodeExcludedProjectsRequest($nodeId),
            DevelopmentNodeExclusionsResponse::class,
            ['List exclusions', 'Listing exclusions', 'Listed exclusions'],
        );

        return $response instanceof DevelopmentNodeExclusionsResponse
            ? $this->renderExclusionList($response)
            : self::FAILURE;
    }
}
