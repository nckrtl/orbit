<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build\Sources;

use App\Domain\Nodes\RoleName;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Metrics\ServiceMetricsConfigRenderer;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Models\Node;

/**
 * The WireGuard scrape site on an Ingress Node that service metrics selects (ADR 0099, ADR 0139).
 */
final readonly class ServiceMetricsCaddySiteSource implements NodeCaddySiteSource
{
    public function __construct(
        private ServiceMetricsProjection $projection,
        private ServiceMetricsConfigRenderer $renderer = new ServiceMetricsConfigRenderer,
    ) {}

    public function sites(Node $node): array
    {
        $metrics = CaddySiteRoles::serving(RoleName::Metrics);

        if (count($metrics) !== 1 || ! $this->projection->forNode($metrics[0]->node, $node)->caddy) {
            return [];
        }

        $address = (string) $node->wireguard_ip;

        return [new CaddySite(
            source: 'service-metrics',
            name: 'scrape',
            listener: CaddyListenerRule::WireGuard,
            hosts: [$address],
            port: (int) ServiceMetricsConfigRenderer::CaddyPort,
            body: $this->renderer->caddy($address, (string) $metrics[0]->node->wireguard_ip),
        )];
    }
}
