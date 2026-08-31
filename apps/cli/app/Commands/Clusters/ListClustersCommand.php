<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Clusters\ListClustersRequest;
use Orbit\Sdk\Responses\Clusters\ClustersResponse;

final class ListClustersCommand extends ClusterCommand
{
    #[\Override]
    protected $signature = 'cluster:list
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List Clusters.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send($connector, new ListClustersRequest, ClustersResponse::class);

        if (! $response instanceof ClustersResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->clusters as $cluster) {
            $rows[] = [
                $cluster->id,
                $cluster->name,
                $cluster->tld ?? '-',
                $cluster->state,
                count($cluster->nodes),
                $this->nodeLabel($cluster->router),
            ];
        }

        $this->table(['ID', 'Name', 'TLD', 'State', 'Nodes', 'Router'], $rows);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
