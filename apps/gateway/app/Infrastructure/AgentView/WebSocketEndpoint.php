<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentView;

/**
 * Where the subscriber opens its WebSocket: a TLS connection to `address`, verifying the
 * certificate for `serverName` against `caPath`. The Gateway host does not resolve `*.orbit`
 * names, so the address is the `websocket` role's WireGuard address.
 */
final readonly class WebSocketEndpoint
{
    public function __construct(
        public string $address,
        public int $port,
        public string $serverName,
        public string $path,
        public string $caPath,
    ) {}
}
