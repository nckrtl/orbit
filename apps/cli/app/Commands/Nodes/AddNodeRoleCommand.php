<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleMutationResponse;

final class AddNodeRoleCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:role:add
        {node : Numeric node ID}
        {role : Role name}
        {--converge : Converge an existing assignment}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Add one role assignment to a node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $nodeId = $this->positiveId('node', 'Node', 'node.id_invalid');

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $role = $this->stringArgument('role', 'Role', 'node_role.role_required');

        if ($role === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new AddNodeRoleRequest($nodeId, $role, $this->option('converge') === true),
            NodeRoleMutationResponse::class,
        );

        if (! $response instanceof NodeRoleMutationResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->info("Role [{$response->role}] added to node [{$response->nodeName}] (#{$response->nodeId}).");
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
