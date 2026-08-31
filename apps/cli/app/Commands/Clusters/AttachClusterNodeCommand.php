<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\AttachClusterNodeRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;

final class AttachClusterNodeCommand extends ClusterCommand
{
    #[\Override]
    protected $signature = 'cluster:node:attach
        {cluster : Numeric Cluster ID}
        {node : Numeric Node ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Attach a Node to a Cluster.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $clusterId = $this->clusterId();
        $nodeId = $this->nodeId();

        if ($clusterId === null || $nodeId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $cluster = $this->send(
            $connector,
            new AttachClusterNodeRequest($clusterId, $nodeId),
            ClusterResponse::class,
        );

        if (! $cluster instanceof ClusterResponse) {
            return self::FAILURE;
        }

        return $this->renderCluster($cluster, "Node #{$nodeId} attached to Cluster [{$cluster->name}].");
    }
}
