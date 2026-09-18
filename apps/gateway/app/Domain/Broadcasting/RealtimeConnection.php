<?php

declare(strict_types=1);

namespace App\Domain\Broadcasting;

use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketHostname;

/**
 * Resolves the realtime connection from the active `websocket` role
 * assignment, or null when none is active. Bound scoped to one request:
 * the database lookup this performs happens at most once per request, and
 * only when something actually asks for the connection (a broadcast, or the
 * realtime config/tail endpoints) — never unconditionally on every request.
 */
final class RealtimeConnection
{
    private bool $resolved = false;

    private ?RealtimeConnectionData $connection = null;

    public function __construct(
        private readonly WebSocketCredentialManager $credentials,
        private readonly string $orbitHome,
    ) {}

    public function resolve(): ?RealtimeConnectionData
    {
        if ($this->resolved) {
            return $this->connection;
        }

        $this->resolved = true;
        $credentials = $this->credentials->current();

        if ($credentials === null) {
            return $this->connection = null;
        }

        return $this->connection = new RealtimeConnectionData(
            host: WebSocketHostname::Value,
            port: 443,
            scheme: 'https',
            appId: $credentials->appId,
            key: $credentials->appKey,
            secret: $credentials->appSecret,
            caCertificatePath: rtrim($this->orbitHome, '/').'/ca/root.pem',
        );
    }

    /**
     * Points Laravel's `reverb` broadcast connection at the resolved
     * connection so the Pusher-protocol broadcaster and channel-auth signer
     * use it. Returns false, leaving the connection unconfigured, when no
     * websocket role is active.
     */
    public function configureBroadcasting(): bool
    {
        $connection = $this->resolve();

        if ($connection === null) {
            return false;
        }

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => $connection->key,
            'broadcasting.connections.reverb.secret' => $connection->secret,
            'broadcasting.connections.reverb.app_id' => $connection->appId,
            'broadcasting.connections.reverb.options.host' => $connection->host,
            'broadcasting.connections.reverb.options.port' => $connection->port,
            'broadcasting.connections.reverb.options.scheme' => $connection->scheme,
            'broadcasting.connections.reverb.options.useTLS' => $connection->scheme === 'https',
            'broadcasting.connections.reverb.client_options.verify' => $connection->caCertificatePath,
        ]);

        return true;
    }
}
