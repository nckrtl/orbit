<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\ClearClusterRouterRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;

final class ClearClusterRouterCommand extends ClusterCommand
{
    #[\Override]
    protected $signature = 'cluster:router:clear
        {cluster : Numeric Cluster ID}
        {--force : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Clear the Router from an inactive Cluster.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $clusterId = $this->clusterId();

        if ($clusterId === null || ! $this->confirmed('Router clearing')) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $cluster = $this->send(
            $connector,
            new ClearClusterRouterRequest($clusterId, true),
            ClusterResponse::class,
        );

        if (! $cluster instanceof ClusterResponse) {
            return self::FAILURE;
        }

        return $this->renderCluster($cluster, "Router cleared from Cluster [{$cluster->name}].");
    }
}
