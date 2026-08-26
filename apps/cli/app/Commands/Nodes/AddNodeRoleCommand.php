<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleResponse;

final class AddNodeRoleCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:role:add
        {node : Numeric node ID}
        {role : Role name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Assign one role to a node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $nodeId = $this->positiveId('node', 'Node', 'node.id_invalid');
        $role = $this->stringArgument('role', 'Role', 'node.role_required');

        if ($nodeId === null || $role === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new AddNodeRoleRequest($nodeId, $role),
            NodeRoleResponse::class,
        );

        if (! $response instanceof NodeRoleResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $assignment = $response->assignment;
        $this->info(
            "Role [{$assignment->role}] assigned to node [{$response->nodeName}]; status: {$assignment->status}.",
        );

        if ($assignment->localActionRequired && $assignment->localCommand !== null) {
            $this->line("Local setup required: {$assignment->localCommand}");
        }

        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
