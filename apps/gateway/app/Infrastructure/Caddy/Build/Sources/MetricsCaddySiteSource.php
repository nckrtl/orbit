<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build\Sources;

use App\Domain\Nodes\RoleName;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\NodeCaddySiteUnavailable;
use App\Infrastructure\Metrics\MetricsPublicationRenderer;
use App\Models\Node;

/**
 * `metrics.orbit` on the Node that holds the `gateway` role, proxied to the Node that runs Metrics.
 */
final readonly class MetricsCaddySiteSource implements NodeCaddySiteSource
{
    public function __construct(
        private MetricsPublicationRenderer $renderer = new MetricsPublicationRenderer,
    ) {}

    public function sites(Node $node): array
    {
        if (! CaddySiteRoles::nodeServes($node->id, RoleName::Gateway)) {
            return [];
        }

        $sites = [];

        foreach (CaddySiteRoles::serving(RoleName::Metrics) as $assignment) {
            $gateway = $node->wireguard_ip;
            $metrics = $assignment->node->wireguard_ip;

            if (! is_string($gateway) || $gateway === '') {
                throw NodeCaddySiteUnavailable::wireGuardAddressMissing('metrics', $node->name);
            }

            if (! is_string($metrics) || $metrics === '') {
                throw NodeCaddySiteUnavailable::wireGuardAddressMissing('metrics', $assignment->node->name);
            }

            $sites[] = new CaddySite(
                source: 'metrics',
                name: 'metrics.orbit',
                listener: CaddyListenerRule::WireGuard,
                hosts: ['metrics.orbit'],
                port: 443,
                body: $this->renderer->caddy($metrics, $gateway),
            );
        }

        return $sites;
    }
}
