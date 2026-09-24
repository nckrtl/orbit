<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build\Sources;

use App\Domain\Nodes\RoleName;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\WebSocket\WebSocketCaddySiteRenderer;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\Node;

final readonly class WebSocketCaddySiteSource implements NodeCaddySiteSource
{
    public function __construct(
        private WebSocketCaddySiteRenderer $renderer = new WebSocketCaddySiteRenderer,
        private int $port = 0,
    ) {}

    public function sites(Node $node): array
    {
        if (! CaddySiteRoles::nodeServes($node->id, RoleName::WebSocket)) {
            return [];
        }

        return [new CaddySite(
            source: 'websocket',
            name: WebSocketFootprint::Hostname,
            listener: CaddyListenerRule::Shared,
            hosts: [WebSocketFootprint::Hostname],
            port: 443,
            body: $this->renderer->render($this->port > 0 ? $this->port : (int) config('orbit.websocket.port')),
            bindPlaceholder: WebSocketFootprint::CaddyBindPlaceholder,
        )];
    }
}
