<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\CreateClusterRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;

final class CreateClusterCommand extends ClusterCommand
{
    #[\Override]
    protected $signature = 'cluster:new
        {name : Unique Cluster name}
        {--tld= : Optional development TLD}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a Cluster.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $name = $this->stringArgument('name', 'Cluster name', 'cluster.name_required');

        if ($name === null) {
            return self::FAILURE;
        }

        $tld = $this->option('tld');

        if ($tld !== null && ! is_string($tld)) {
            return $this->renderGatewayFailure('cluster.tld_invalid', 'TLD must be one DNS label.');
        }

        if (is_string($tld) && $tld !== '' && ! $this->validTld($tld)) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $cluster = $this->send(
            $connector,
            new CreateClusterRequest($name, is_string($tld) && $tld !== '' ? $tld : null),
            ClusterResponse::class,
        );

        if (! $cluster instanceof ClusterResponse) {
            return self::FAILURE;
        }

        return $this->renderCluster($cluster, "Cluster [{$cluster->name}] created.");
    }
}
