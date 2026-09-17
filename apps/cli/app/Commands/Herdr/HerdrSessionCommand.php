<?php

declare(strict_types=1);

namespace App\Commands\Herdr;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayConfigException;
use App\Services\Extensions\LocalExtensionState;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Herdr\ListHerdrSessionsRequest;
use Orbit\Sdk\Responses\Herdr\HerdrSessionResponse;
use Orbit\Sdk\Responses\Herdr\HerdrSessionsResponse;

abstract class HerdrSessionCommand extends GatewayCommand
{
    public function isHidden(): bool
    {
        try {
            return ! app(LocalExtensionState::class)->enabled('herdr');
        } catch (GatewayConfigException) {
            return true;
        }
    }

    protected function guardExtension(): ?int
    {
        try {
            $enabled = app(LocalExtensionState::class)->enabled('herdr');
        } catch (GatewayConfigException) {
            return $this->renderGatewayFailure(
                'extension.config_invalid',
                'Orbit extension configuration is invalid or not private.',
            );
        }

        if ($enabled) {
            return null;
        }

        return $this->renderGatewayFailure(
            'extension.disabled',
            'The Herdr extension is disabled. Run `orbit extension:enable herdr` first.',
        );
    }

    protected function sessionName(): ?string
    {
        $name = $this->stringArgument('session', 'Herdr session name', 'herdr.session_required');

        if ($name === null) {
            return null;
        }

        if (strlen($name) > 48 || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $name) !== 1) {
            $this->renderGatewayFailure(
                'herdr.session_invalid',
                'Herdr session name is invalid.',
            );

            return null;
        }

        return $name;
    }

    protected function requiredNodeId(GatewayConnector $connector): ?int
    {
        return $this->resolveNodeId($connector, $this->option('node'));
    }

    protected function resolveSession(
        GatewayConnector $connector,
        int $nodeId,
        string $name,
    ): ?HerdrSessionResponse {
        $sessions = $this->send(
            $connector,
            new ListHerdrSessionsRequest($nodeId),
            HerdrSessionsResponse::class,
        );

        if (! $sessions instanceof HerdrSessionsResponse) {
            return null;
        }

        foreach ($sessions->sessions as $session) {
            if ($session->session === $name) {
                return $session;
            }
        }

        $this->renderGatewayFailure(
            'herdr.session_not_found',
            "Herdr session [{$name}] is not registered on this Node.",
        );

        return null;
    }

    /** @return array<string, mixed> */
    protected function sanitizedSessionPayload(HerdrSessionResponse $session): array
    {
        $payload = $session->toArray();
        unset($payload['request_id']);
        $payload['request_id'] = $session->requestId;

        return $payload;
    }

    protected function renderRemovedSession(HerdrSessionResponse $session, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($this->sanitizedSessionPayload($session));

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($message, [
            'ID' => $session->id,
            'Session' => $session->session,
            'Node' => $session->node,
            'Node ID' => $session->nodeId,
            'User' => $session->user,
            'Management' => $session->management,
            'Request ID' => $session->requestId,
        ]));

        return self::SUCCESS;
    }

    protected function renderSession(HerdrSessionResponse $session, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($this->sanitizedSessionPayload($session));

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($message, [
            'ID' => $session->id,
            'Node' => $session->node,
            'Node ID' => $session->nodeId,
            'Session' => $session->session,
            'User' => $session->user,
            'Process ID' => $session->processId,
            'Management' => $session->management,
            'Observer URL' => $session->observerUrl,
            'Status' => $session->status,
            'Herdr version' => $session->herdrVersion,
            'Protocol' => $session->protocol,
            'Process health' => $session->health['process'],
            'Listener health' => $session->health['listener'],
            'Session health' => $session->health['session'],
            'Failed step' => $session->failedStep,
            'Error code' => $session->errorCode,
            'Request ID' => $session->requestId,
        ]));

        return self::SUCCESS;
    }
}
