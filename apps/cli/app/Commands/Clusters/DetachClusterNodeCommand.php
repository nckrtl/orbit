<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\DetachClusterNodeRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;

final class DetachClusterNodeCommand extends ClusterCommand
{
    #[\Override]
    protected $signature = 'cluster:node:detach
        {cluster : Numeric Cluster ID}
        {node : Numeric Node ID}
        {--force : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Detach a Node from a Cluster.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $clusterId = $this->clusterId();
        $nodeId = $this->nodeId();

        if ($clusterId === null || $nodeId === null || ! $this->confirmed('Node detachment')) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $cluster = $this->send(
            $connector,
            new DetachClusterNodeRequest($clusterId, $nodeId, true),
            ClusterResponse::class,
        );

        if (! $cluster instanceof ClusterResponse) {
            return self::FAILURE;
        }

        return $this->renderCluster($cluster, "Node #{$nodeId} detached from Cluster [{$cluster->name}].");
    }
}
