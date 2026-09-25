<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build\Sources;

use App\Domain\Nodes\RoleName;
use App\Infrastructure\Analytics\AnalyticsCaddySiteRenderer;
use App\Infrastructure\Analytics\AnalyticsFootprint;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\NodeCaddySiteUnavailable;
use App\Models\Node;

final readonly class AnalyticsCaddySiteSource implements NodeCaddySiteSource
{
    public function __construct(
        private AnalyticsCaddySiteRenderer $renderer = new AnalyticsCaddySiteRenderer,
        private CaddySiteCertificates $certificates = new CaddySiteCertificates,
    ) {}

    public function sites(Node $node): array
    {
        if (
            ! CaddySiteRoles::nodeServes($node->id, RoleName::Analytics)
            || ! $this->certificates->published($node->id, CaddySiteCertificates::Analytics)
        ) {
            return [];
        }

        $address = $node->wireguard_ip;

        if (! is_string($address) || $address === '') {
            throw NodeCaddySiteUnavailable::wireGuardAddressMissing('analytics', $node->name);
        }

        return [new CaddySite(
            source: 'analytics',
            name: AnalyticsFootprint::Hostname,
            listener: CaddyListenerRule::Shared,
            hosts: [AnalyticsFootprint::Hostname],
            port: 443,
            body: $this->renderer->render($address),
            bindPlaceholder: AnalyticsFootprint::CaddyBindPlaceholder,
        )];
    }
}
