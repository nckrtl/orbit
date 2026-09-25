<?php

declare(strict_types=1);

namespace App\Domain\Broadcasting;

use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketHostname;
use Illuminate\Support\Facades\Broadcast;

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

    /** @var list<RealtimeConnectionData> The old Node's Reverb during a `websocket` move. */
    private array $others = [];

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

        $connections = array_map(
            fn (?string $address): RealtimeConnectionData => new RealtimeConnectionData(
                host: WebSocketHostname::Value,
                port: 443,
                scheme: 'https',
                appId: $credentials->appId,
                key: $credentials->appKey,
                secret: $credentials->appSecret,
                caCertificatePath: rtrim($this->orbitHome, '/').'/ca/root.pem',
                resolveAddress: $address,
            ),
            $credentials->addresses() === [] ? [$credentials->servingAddress] : $credentials->addresses(),
        );
        $this->others = array_slice($connections, 1);

        return $this->connection = $connections[0];
    }

    /**
     * Every Reverb server that holds clients, the serving one first. During a `websocket` move the old
     * Node's server still holds the clients that connected before DNS moved, so a broadcast goes to both.
     *
     * @return list<RealtimeConnectionData>
     */
    public function all(): array
    {
        $connection = $this->resolve();

        return $connection === null ? [] : [$connection, ...$this->others];
    }

    /**
     * Points Laravel's `reverb` broadcast connection at the resolved
     * connection so the Pusher-protocol broadcaster and channel-auth signer
     * use it. Returns false, leaving the connection unconfigured, when no
     * websocket role is active.
     */
    public function configureBroadcasting(?RealtimeConnectionData $connection = null): bool
    {
        $connection ??= $this->resolve();

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
            // A broadcast runs inside the request that changed the record, so a stuck server must not hold it.
            'broadcasting.connections.reverb.client_options.connect_timeout' => 2,
            'broadcasting.connections.reverb.client_options.timeout' => 5,
            'broadcasting.connections.reverb.client_options.curl' => $connection->resolveAddress === null
                ? []
                : [CURLOPT_RESOLVE => ["{$connection->host}:{$connection->port}:{$connection->resolveAddress}"]],
        ]);

        return true;
    }

    /**
     * Registers the private `orbit` channel on the current default driver.
     *
     * Channel callbacks live on the resolved driver. Boot registers `orbit` on
     * the default `null` connection; after {@see configureBroadcasting()} flips
     * the default to `reverb`, auth must register the channel on that driver or
     * `Broadcast::auth()` throws and the API renders `gateway.unhandled`.
     */
    public function registerChannelAuthorizers(): void
    {
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
    }
}
