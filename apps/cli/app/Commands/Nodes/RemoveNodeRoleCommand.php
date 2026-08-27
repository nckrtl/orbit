<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRoleRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleMutationResponse;

/** @mago-expect lint:cyclomatic-complexity The preview, confirmation, and forced retry contract has several explicit branches. */
final class RemoveNodeRoleCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:role:remove
        {node : Numeric node ID}
        {role : Role name}
        {--force : Confirm destructive role removal and dependent cleanup}
        {--purge-data : Request supported role-owned data cleanup}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one role assignment from a node.';

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

        if ($this->option('force') !== true) {
            $preview = $this->previewRemoval($connector, $nodeId, $role);

            if ($preview !== null) {
                return $preview;
            }
        }

        $response = $this->send(
            $connector,
            new RemoveNodeRoleRequest(
                nodeId: $nodeId,
                role: $role,
                force: true,
                purgeData: $this->option('purge-data') === true,
            ),
            NodeRoleMutationResponse::class,
        );

        if (! $response instanceof NodeRoleMutationResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->info("Role [{$response->role}] removed from node [{$response->nodeName}] (#{$response->nodeId}).");
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    private function previewRemoval(
        \Orbit\Sdk\GatewayConnector $connector,
        int $nodeId,
        string $role,
    ): ?int {
        try {
            $this->sendOrThrow(
                $connector,
                new RemoveNodeRoleRequest(
                    nodeId: $nodeId,
                    role: $role,
                    force: false,
                    purgeData: false,
                ),
                NodeRoleMutationResponse::class,
            );

            return $this->renderGatewayFailure(
                'gateway.invalid_response',
                'Gateway response is invalid.',
            );
        } catch (GatewayApiException $exception) {
            if (! $this->isConsentPreview($exception)) {
                return $this->renderGatewayFailure(
                    $exception->errorCode() ?? 'gateway.request_failed',
                    $exception->getMessage(),
                    $exception->requestId(),
                );
            }

            if ($this->option('json') === true || ! $this->input->isInteractive()) {
                return $this->renderGatewayFailure(
                    $exception->errorCode() ?? 'gateway.request_failed',
                    $exception->getMessage(),
                    $exception->requestId(),
                );
            }

            $dependents = $this->dependents($exception);

            if ($dependents !== []) {
                $this->line('Dependent resources:');

                foreach ($dependents as $dependent) {
                    $this->line("  - {$dependent}");
                }
            }

            if (! $this->confirm("Remove role '{$role}' from node #{$nodeId}?", false)) {
                return self::FAILURE;
            }
        }

        return null;
    }

    private function isConsentPreview(GatewayApiException $exception): bool
    {
        $details = $exception->details();

        return (
            $exception->errorCode() === 'validation.failed'
            && ($details['field'] ?? null) === 'force'
            && ($details['reason'] ?? null) === 'destructive_consent_required'
        );
    }

    /** @return list<string> */
    private function dependents(GatewayApiException $exception): array
    {
        /** @mago-expect analysis:mixed-assignment Gateway error details cross an untyped boundary. */
        $dependents = $exception->details()['dependents'] ?? null;

        if (! is_array($dependents)) {
            return [];
        }

        return array_values(array_filter($dependents, is_string(...)));
    }
}
