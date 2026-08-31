<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\ShowClusterRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;

final class ShowClusterCommand extends ClusterCommand
{
    #[\Override]
    protected $signature = 'cluster:show
        {cluster : Numeric Cluster ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show a Cluster.';

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

        $cluster = $this->send($connector, new ShowClusterRequest($clusterId), ClusterResponse::class);

        if (! $cluster instanceof ClusterResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($cluster->toArray());

            return self::SUCCESS;
        }

        $this->info("{$cluster->name}: {$cluster->state} (#{$cluster->id})");
        $this->line('TLD: '.($cluster->tld ?? '-'));
        $this->line('Router: '.$this->nodeLabel($cluster->router));
        $this->line('Nodes: '.$this->nodeList($cluster->nodes));
        $this->line("Request ID: {$cluster->requestId}");

        return self::SUCCESS;
    }
}
