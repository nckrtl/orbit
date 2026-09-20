<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Domain\Analytics\PlausibleProcess;

/**
 * Renders the Caddy site that terminates Orbit-CA TLS for `analytics.orbit` on the analytics
 * role's own node and reverse-proxies to the `plausible` Process, which listens on the node's
 * WireGuard address only. Plausible has its own accounts, so the site adds no authorization.
 */
final readonly class AnalyticsCaddySiteRenderer
{
    public function render(string $wireguardIp): string
    {
        $marker = AnalyticsFootprint::CaddyFragmentMarker;
        $host = AnalyticsFootprint::Hostname;
        $certificate = AnalyticsFootprint::CertificateCurrentDirectory;
        $bind = AnalyticsFootprint::CaddyBindPlaceholder;
        $port = PlausibleProcess::PORT;

        return <<<CADDY
            {$marker}
            {$host} {
                bind {$bind}
                tls {$certificate}/analytics.pem {$certificate}/analytics.key
                reverse_proxy {$wireguardIp}:{$port}
            }
            CADDY;
    }
}
