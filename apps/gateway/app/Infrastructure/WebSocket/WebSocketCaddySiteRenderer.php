<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

/**
 * Renders the Caddy site that terminates Orbit-CA TLS for `reverb.orbit` on
 * the websocket role's own node and reverse-proxies to the local Reverb
 * process. `reverse_proxy` admits a WebSocket upgrade by default.
 */
final readonly class WebSocketCaddySiteRenderer
{
    public function render(int $port): string
    {
        $marker = WebSocketFootprint::CaddyFragmentMarker;
        $host = WebSocketFootprint::Hostname;
        $certificate = WebSocketFootprint::CertificateCurrentDirectory;
        $bind = WebSocketFootprint::CaddyBindPlaceholder;

        return <<<CADDY
            {$marker}
            {$host} {
                bind {$bind}
                tls {$certificate}/reverb.pem {$certificate}/reverb.key
                reverse_proxy 127.0.0.1:{$port}
            }

            CADDY;
    }
}
