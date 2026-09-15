<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

final class ListNodesCommand extends NodeCommand
{
    #[\Override]
    protected $signature = 'node:list
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List nodes registered with the active gateway.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress($connector, new ListNodesRequest, NodesResponse::class, ['List Nodes', 'Loading Nodes', 'Loaded Nodes']);

        if (! $response instanceof NodesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->nodes as $node) {
            $rows[] = [
                $node->id,
                $node->name,
                $node->status,
                $node->roles === [] ? null : implode(', ', $node->roles),
                $node->platform ?? null,
                NodeOutput::tld($node->tld),
                $node->user,
                $node->clusterId ?? null,
                $node->wireguardIp ?? null,
                $node->lanIp ?? null,
            ];
        }

        if ($rows === []) {
            $this->writeHumanMessage('No nodes.');
            $this->writeHumanMessage("Request ID: {$response->requestId}");

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table([
            'ID',
            'Name',
            'Status',
            'Roles',
            'Platform',
            'TLD',
            'User',
            'Cluster',
            'WireGuard',
            'LAN',
        ], $rows));

        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
