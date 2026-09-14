<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\DestroyClusterRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;

final class DestroyClusterCommand extends ClusterCommand
{
    #[\Override]
    protected $signature = 'cluster:destroy
        {cluster : Numeric Cluster ID}
        {--force : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove an empty Cluster.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $clusterId = $this->clusterId();

        if ($clusterId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $existing = $this->existingCluster($connector, $clusterId);

        if ($existing === null || ! $this->confirmed('removal')) {
            return self::FAILURE;
        }

        $cluster = $this->send($connector, new DestroyClusterRequest($clusterId), ClusterResponse::class);

        if (! $cluster instanceof ClusterResponse) {
            return self::FAILURE;
        }

        return $this->renderCluster($cluster, "Cluster [{$cluster->name}] removed.");
    }
}
