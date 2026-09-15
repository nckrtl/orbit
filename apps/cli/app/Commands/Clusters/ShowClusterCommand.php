<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
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

        $cluster = $this->sendWithProgress($connector, new ShowClusterRequest($clusterId), ClusterResponse::class, ['Show Cluster', 'Loading Cluster', 'Loaded Cluster']);

        if (! $cluster instanceof ClusterResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($cluster->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Cluster: {$cluster->name}", [
            'ID' => $cluster->id,
            'State' => $cluster->state,
            'TLD' => $cluster->tld === null ? null : '.'.ltrim($cluster->tld, '.'),
            'Router' => $this->nodeLabel($cluster->router),
            'Nodes' => $this->nodeList($cluster->nodes),
            'Request ID' => $cluster->requestId,
        ]));

        return self::SUCCESS;
    }
}
