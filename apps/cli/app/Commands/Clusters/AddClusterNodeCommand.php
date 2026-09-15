<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\AddClusterNodeRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;

final class AddClusterNodeCommand extends ClusterCommand
{
    #[\Override]
    protected $signature = 'cluster:node:add
        {cluster : Numeric Cluster ID}
        {node : Numeric Node ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Attach a Node to a Cluster.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $clusterId = $this->clusterId();
        if ($clusterId === null) {
            return self::FAILURE;
        }

        $nodeId = $this->nodeId();
        if ($nodeId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $cluster = $this->sendWithProgress(
            $connector,
            new AddClusterNodeRequest($clusterId, $nodeId),
            ClusterResponse::class,
            ['Attach Node', 'Attaching Node', 'Attached Node'],
        );

        if (! $cluster instanceof ClusterResponse) {
            return self::FAILURE;
        }

        return $this->renderCluster($cluster, "Node #{$nodeId} attached to Cluster [{$cluster->name}].");
    }
}
