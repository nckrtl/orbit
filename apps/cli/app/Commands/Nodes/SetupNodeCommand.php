<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Data\NodeSetupFacts;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Services\NodeSetup\ControllingTerminal;
use App\Services\NodeSetup\MacOsAppDevSetupRunner;
use App\Support\GatewayFailureRenderer;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Nodes\FetchAppDevSetupScriptRequest;
use Orbit\Sdk\Requests\Nodes\SubmitAppDevSetupResultRequest;
use Orbit\Sdk\Responses\Nodes\AppDevSetupScriptResponse;
use Orbit\Sdk\Responses\Nodes\NodeRoleResponse;
use Saloon\Exceptions\Request\FatalRequestException;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity The command preserves each frozen request-count and request-ID branch. */
final class SetupNodeCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:setup
        {role : Local role to set up}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Set up one supported role on this local node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
        NodeSetupFacts $facts,
        ControllingTerminal $terminal,
        MacOsAppDevSetupRunner $runner,
    ): int {
        if ($this->argument('role') !== 'app-dev') {
            return $this->renderGatewayFailure(
                'node.setup_role_invalid',
                'Only the app-dev role supports local setup.',
            );
        }

        if (! $this->input->isInteractive()) {
            return $this->renderConfirmationRequired();
        }

        $platform = $facts->platform();
        $architecture = $facts->architecture();

        if (
            $platform !== 'darwin'
            || $architecture === ''
            || strlen($architecture) > 64
            || preg_match('/[\x00-\x1F\x7F]/', $architecture) === 1
        ) {
            return $this->renderGatewayFailure(
                'node.setup_platform_invalid',
                'Local app-dev setup requires macOS.',
            );
        }

        $identity = $facts->identity();

        if ($identity === null) {
            return $this->renderGatewayFailure(
                'node.setup_identity_unavailable',
                'Could not determine the current local user.',
            );
        }

        if (! $terminal->isAvailable()) {
            return $this->renderConfirmationRequired();
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $scriptResponse = $this->sendSetupRequest(
            $connector,
            new FetchAppDevSetupScriptRequest(
                platform: $platform,
                architecture: $architecture,
                username: $identity['username'],
                homeDirectory: $identity['home_directory'],
            ),
            AppDevSetupScriptResponse::class,
        );

        if (! $scriptResponse instanceof AppDevSetupScriptResponse) {
            return self::FAILURE;
        }

        try {
            $confirmed = $terminal->confirm($scriptResponse->summary);
        } catch (Throwable) {
            $confirmed = false;
        }

        if (! $confirmed) {
            return $this->renderGatewayFailure(
                'node.setup_cancelled',
                'Local setup was cancelled.',
                $scriptResponse->requestId,
            );
        }

        $trapped = false;

        try {
            $this->trap(SIGINT, static function () use ($runner): void {
                $runner->requestInterrupt();
            });
            $trapped = true;

            $result = $runner->run($scriptResponse->script(), $terminal);
        } catch (Throwable) {
            return $this->renderGatewayFailure(
                'node.setup_local_failed',
                'Local setup failed.',
                $scriptResponse->requestId,
            );
        } finally {
            if ($trapped) {
                $this->untrap();
            }
        }

        $response = $this->sendSetupRequest(
            $connector,
            new SubmitAppDevSetupResultRequest(
                exitCode: $result->exitCode,
                diagnostics: $result->diagnostics,
            ),
            NodeRoleResponse::class,
        );

        if (! $response instanceof NodeRoleResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->info(
            "Role [{$response->assignment->role}] is {$response->assignment->status} on node [{$response->nodeName}].",
        );
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    private function renderConfirmationRequired(): int
    {
        return $this->renderGatewayFailure(
            'node.setup_confirmation_required',
            'Interactive confirmation through a controlling terminal is required.',
        );
    }

    private function sendSetupRequest(
        GatewayConnector $connector,
        GatewayRequest $request,
        string $responseClass,
    ): ?object {
        try {
            $response = $connector->send($request);
        } catch (GatewayApiException $exception) {
            GatewayFailureRenderer::write(
                $this,
                $exception->errorCode() ?? 'gateway.request_failed',
                $exception->getMessage(),
                $exception->requestId(),
            );

            return null;
        } catch (FatalRequestException) {
            GatewayFailureRenderer::write($this, 'gateway.unreachable', 'Could not reach the gateway.');

            return null;
        }

        try {
            /** @mago-expect analysis:mixed-assignment Saloon returns setup DTOs through a mixed boundary. */
            $dto = $response->dto();
        } catch (GatewayApiException $exception) {
            if ($response->successful()) {
                GatewayFailureRenderer::write($this, 'gateway.invalid_response', 'Gateway response is invalid.');

                return null;
            }

            GatewayFailureRenderer::write(
                $this,
                $exception->errorCode() ?? 'gateway.request_failed',
                $exception->getMessage(),
                $exception->requestId(),
            );

            return null;
        } catch (Throwable) {
            GatewayFailureRenderer::write($this, 'gateway.invalid_response', 'Gateway response is invalid.');

            return null;
        }

        if (! is_object($dto) || ! $dto instanceof $responseClass) {
            GatewayFailureRenderer::write($this, 'gateway.invalid_response', 'Gateway response is invalid.');

            return null;
        }

        return $dto;
    }
}
