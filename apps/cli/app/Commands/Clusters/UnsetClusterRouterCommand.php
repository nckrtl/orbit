<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\UnsetClusterRouterRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;

final class UnsetClusterRouterCommand extends ClusterCommand
{
    #[\Override]
    protected $signature = 'cluster:router:unset
        {cluster : Numeric Cluster ID}
        {--force : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Clear the Router from an inactive Cluster.';

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

        if ($existing === null || ! $this->confirmed('Router clearing', $existing)) {
            return self::FAILURE;
        }

        $cluster = $this->sendWithProgress(
            $connector,
            new UnsetClusterRouterRequest($clusterId, true),
            ClusterResponse::class,
            ['Clear Cluster Router', 'Clearing Cluster Router', 'Cleared Cluster Router'],
        );

        if (! $cluster instanceof ClusterResponse) {
            return self::FAILURE;
        }

        return $this->renderCluster($cluster, "Router cleared from Cluster [{$cluster->name}].");
    }
}
