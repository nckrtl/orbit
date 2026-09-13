<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Nodes\ListNodeRolesRequest;
use Orbit\Sdk\Responses\Nodes\NodeRolesResponse;

final class ListNodeRolesCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:role:list
        {node : Node ID or name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List role assignments for one node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->argument('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $response = $this->send($connector, new ListNodeRolesRequest($nodeId), NodeRolesResponse::class);

        if (! $response instanceof NodeRolesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        if ($response->assignments === []) {
            $this->line('No roles.');
            $this->line("Request ID: {$response->requestId}");

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->assignments as $assignment) {
            $rows[] = [
                $assignment->id,
                $assignment->role,
                $assignment->status,
                $assignment->failedStep ?? '-',
                $assignment->errorCode ?? '-',
            ];
        }

        $this->table(['ID', 'Role', 'Status', 'Failed step', 'Error code'], $rows);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
