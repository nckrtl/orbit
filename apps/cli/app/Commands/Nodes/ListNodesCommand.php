<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

final class ListNodesCommand extends GatewayCommand
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

        $response = $this->send($connector, new ListNodesRequest, NodesResponse::class);

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
                $node->roles === [] ? '-' : implode(', ', $node->roles),
                $node->platform ?? '-',
                $node->tld ?? '-',
                $node->user,
                $node->wireguardAddress ?? '-',
            ];
        }

        if ($rows === []) {
            $this->line('No nodes.');
            $this->line("Request ID: {$response->requestId}");

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'Status', 'Roles', 'Platform', 'TLD', 'User', 'WireGuard'], $rows);

        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
