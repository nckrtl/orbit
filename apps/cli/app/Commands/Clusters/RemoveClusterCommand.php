<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\RemoveClusterRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;

final class RemoveClusterCommand extends ClusterCommand
{
    #[\Override]
    protected $signature = 'cluster:remove
        {cluster : Numeric Cluster ID}
        {--force : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove an empty Cluster.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $clusterId = $this->clusterId();

        if ($clusterId === null || ! $this->confirmed('removal')) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $cluster = $this->send($connector, new RemoveClusterRequest($clusterId), ClusterResponse::class);

        if (! $cluster instanceof ClusterResponse) {
            return self::FAILURE;
        }

        return $this->renderCluster($cluster, "Cluster [{$cluster->name}] removed.");
    }
}
