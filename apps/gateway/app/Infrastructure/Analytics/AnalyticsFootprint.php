<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Domain\Analytics\AnalyticsHostname;

/**
 * Every trace the analytics role leaves on its node beside the `plausible` Process, and the proof
 * Orbit owns each one.
 */
final readonly class AnalyticsFootprint
{
    public const string Hostname = AnalyticsHostname::Value;

    /** The first line of the Caddy fragment, and the only proof that Orbit wrote it. */
    public const string CaddyFragment = 'analytics.caddy';

    public const string CaddyFragmentMarker = '# Managed by Orbit: analytics';

    /** Replaced on the node with the address its other Caddy sites already bind. */
    public const string CaddyBindPlaceholder = '__ORBIT_ANALYTICS_BIND__';

    public const string CertificateVersionsDirectory = '/etc/caddy/orbit-analytics-cert-versions';

    public const string CertificateCurrentDirectory = '/etc/caddy/orbit-analytics-cert-current';

    public const string CertificateOwnershipMarker = 'analytics-certificate';

    public const string CaddyVersionsDirectory = '/etc/caddy/orbit-versions';

    public const string CaddyfilePath = '/etc/caddy/Caddyfile';

    public const string CaddyServiceName = 'caddy';

    public const string FirewallComment = 'orbit:analytics-https';

    public const string WireGuardInterface = 'orbit';
}
