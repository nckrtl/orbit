<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressState;
use App\Support\GatewayFailureRenderer;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\RelocateNodeRoleRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleMutationResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

final class RelocateNodeRoleCommand extends NodeCommand
{
    #[\Override]
    protected $signature = 'node:role:relocate
        {node : Node ID or name of the target}
        {role : Role name}
        {--force : Confirm the role transfer}
        {--from= : Optional source Node ID or name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Move a relocatable singleton role to another node.';

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

        $fromOption = $this->stringOption('from');
        $nodes = $this->nodesForResolution($connector, $this->argument('node'), $fromOption);

        if ($nodes === false) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->argument('node'), $nodes);

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $fromId = null;

        if ($fromOption !== null) {
            $fromId = $this->resolveNodeId($connector, $fromOption, $nodes);

            if ($fromId === null) {
                return self::FAILURE;
            }
        }

        if ($this->option('force') !== true) {
            $preview = $this->previewRelocation($connector, $nodeId, $role, $fromId);

            if ($preview !== null) {
                return $preview;
            }
        }

        $response = $this->sendWithProgress(
            $connector,
            new RelocateNodeRoleRequest($nodeId, $role, force: true, from: $fromId),
            NodeRoleMutationResponse::class,
            ['Relocate Node role', 'Relocating Node role', 'Relocated Node role'],
            NodeOutput::mutationState(...),
        );

        if (! $response instanceof NodeRoleMutationResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Role [{$response->role}] relocated to node [{$response->nodeName}] (#{$response->nodeId}).");
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    private function nodesForResolution(GatewayConnector $connector, mixed $target, ?string $from): NodesResponse|null|false
    {
        $needsList = $this->needsNodeList($target) || ($from !== null && $this->needsNodeList($from));

        if (! $needsList) {
            return null;
        }

        $nodes = $this->sendWithProgress(
            $connector,
            new ListNodesRequest,
            NodesResponse::class,
            ['Resolve Node', 'Resolving Node', 'Loaded Nodes'],
        );

        return $nodes instanceof NodesResponse ? $nodes : false;
    }

    private function needsNodeList(mixed $reference): bool
    {
        return is_string($reference)
            && trim($reference) !== ''
            && preg_match('/\A-?[0-9]+\z/D', trim($reference)) !== 1;
    }

    private function previewRelocation(
        GatewayConnector $connector,
        int $nodeId,
        string $role,
        ?int $fromId,
    ): ?int {
        $progress = $this->progressDisplay('Review Node role relocation');
        $progress->admit('preview', 'Review relocation', 'Reviewing relocation', 'Reviewed relocation');

        try {
            $exception = $progress->during('preview', function () use ($connector, $nodeId, $role, $fromId): GatewayApiException {
                try {
                    $this->sendOrThrow(
                        $connector,
                        new RelocateNodeRoleRequest($nodeId, $role, force: false, from: $fromId),
                        NodeRoleMutationResponse::class,
                    );
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
        $progress->finish('Relocation requires consent.');

        if (! $this->consoleMode()->mayPrompt) {
            return $this->renderGatewayFailure(
                $exception->errorCode() ?? 'gateway.request_failed',
                $exception->getMessage(),
                $exception->requestId(),
            );
        }

        if (! $this->confirmAction(
            "Relocate role '{$role}' onto node #{$nodeId}?",
            'Node role relocation cancelled.',
            option: 'force',
        )) {
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
}
