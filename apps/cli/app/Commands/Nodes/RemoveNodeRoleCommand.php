<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressState;
use App\Support\GatewayFailureRenderer;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRoleRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleMutationResponse;

final class RemoveNodeRoleCommand extends NodeCommand
{
    #[\Override]
    protected $signature = 'node:role:remove
        {node : Node ID or name}
        {role : Role name}
        {--force : Confirm destructive role removal and dependent cleanup}
        {--purge-data : Request supported role-owned data cleanup}
        {--offline : Remove the role from a node Orbit cannot reach}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one role assignment from a node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $role = $this->stringArgument('role', 'Role', 'node_role.role_required');

        if ($role === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->argument('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        if ($this->option('force') !== true) {
            $preview = $this->previewRemoval($connector, $nodeId, $role);

            if ($preview !== null) {
                return $preview;
            }
        }

        $response = $this->sendWithProgress(
            $connector,
            new RemoveNodeRoleRequest(
                nodeId: $nodeId,
                role: $role,
                force: true,
                purgeData: $this->option('purge-data') === true,
                offline: $this->option('offline') === true,
            ),
            NodeRoleMutationResponse::class,
            ['Remove Node role', 'Removing Node role', 'Removed Node role'],
            static fn (object $response): ProgressState => NodeOutput::mutationState($response, removing: true),
        );

        if (! $response instanceof NodeRoleMutationResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Role [{$response->role}] removed from node [{$response->nodeName}] (#{$response->nodeId}).");
        ConsoleWriter::write($this->output, NodeOutput::degradationAdvisory(
            $this->humanRenderer(),
            $this->consoleMode(),
            $response->nodeName,
            $response->degradation,
            [],
            $response->retainedOnNode,
            $response->followUp,
        ));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    private function previewRemoval(
        GatewayConnector $connector,
        int $nodeId,
        string $role,
    ): ?int {
        $progress = $this->progressDisplay('Review Node role removal');
        $progress->admit('preview', 'Review removal', 'Reviewing removal', 'Reviewed removal');

        try {
            $exception = $progress->during('preview', function () use ($connector, $nodeId, $role): GatewayApiException {
                try {
                    $this->sendOrThrow($connector, new RemoveNodeRoleRequest(
                        nodeId: $nodeId, role: $role, force: false, purgeData: false, offline: false,
                    ), NodeRoleMutationResponse::class);
                } catch (GatewayApiException $exception) {
                    if ($this->isConsentPreview($exception)) {
                        return $exception;
                    }

                    throw $exception;
                }

                throw new GatewayApiException('Gateway response is invalid.', 'gateway.invalid_response');
            });
        } catch (GatewayApiException $exception) {
            return $this->renderPreviewFailure($exception);
        }

        $progress->complete('preview', ProgressState::Success);
        $progress->finish('Removal requires consent.');

        if (! $this->consoleMode()->mayPrompt) {
            return $this->renderGatewayFailure(
                $exception->errorCode() ?? 'gateway.request_failed',
                $exception->getMessage(),
                $exception->requestId(),
            );
        }

        $dependents = $this->dependents($exception);

        if ($dependents !== []) {
            ConsoleWriter::write($this->output, $this->humanRenderer()->properties([
                ['title' => 'Dependent resources:', 'items' => array_map(
                    static fn (string $dependent): array => ['label' => $dependent, 'fields' => []], $dependents)],
            ]));
        }

        $effect = $this->option('purge-data') === true ? ' and purge supported role-owned data' : '';

        if (! $this->confirmAction("Remove role '{$role}' from node #{$nodeId}{$effect}?",
            'Node role removal cancelled.', option: 'force')) {
            return self::FAILURE;
        }

        return null;
    }

    private function renderPreviewFailure(GatewayApiException $exception): int
    {
        $code = $exception->errorCode() ?? 'gateway.request_failed';

        return $this->renderGatewayFailure(
            $code,
            $exception->getMessage(),
            $exception->requestId(),
            details: $code === 'validation.failed'
                ? GatewayFailureRenderer::fieldDetails($exception->details())
                : [],
        );
    }

    private function isConsentPreview(GatewayApiException $exception): bool
    {
        $details = $exception->details();

        return
            $exception->errorCode() === 'validation.failed'
            && ($details['field'] ?? null) === 'force'
            && ($details['reason'] ?? null) === 'destructive_consent_required';
    }

    /** @return list<string> */
    private function dependents(GatewayApiException $exception): array
    {
        $dependents = $exception->details()['dependents'] ?? null;

        if (! is_array($dependents)) {
            return [];
        }

        return array_values(array_filter($dependents, is_string(...)));
    }
}
