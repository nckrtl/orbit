<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressState;
use App\Support\GatewayFailureRenderer;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\RelocateNodeRoleRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleMutationResponse;

final class RelocateNodeRoleCommand extends NodeCommand
{
    #[\Override]
    protected $signature = 'node:role:relocate
        {node : Node ID or name of the target}
        {role : Role name}
        {--force : Confirm the gateway role transfer}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Move the singleton gateway role to another node.';

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
            $preview = $this->previewRelocation($connector, $nodeId, $role);

            if ($preview !== null) {
                return $preview;
            }
        }

        $response = $this->sendWithProgress(
            $connector,
            new RelocateNodeRoleRequest($nodeId, $role, force: true),
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

    private function previewRelocation(
        GatewayConnector $connector,
        int $nodeId,
        string $role,
    ): ?int {
        $progress = $this->progressDisplay('Review Node role relocation');
        $progress->admit('preview', 'Review relocation', 'Reviewing relocation', 'Reviewed relocation');

        try {
            $exception = $progress->during('preview', function () use ($connector, $nodeId, $role): GatewayApiException {
                try {
                    $this->sendOrThrow(
                        $connector,
                        new RelocateNodeRoleRequest($nodeId, $role, force: false),
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
