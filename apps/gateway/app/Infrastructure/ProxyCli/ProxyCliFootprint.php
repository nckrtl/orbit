<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\ProxyCli\ProxyCliHostname;

/**
 * Every trace proxycli leaves on its Node beside the collector Process, and the proof Orbit owns each one.
 */
final readonly class ProxyCliFootprint
{
    public const string Hostname = ProxyCliHostname::Value;

    public const string CaddyFragment = 'proxycli.caddy';

    public const string CaddyFragmentMarker = '# Managed by Orbit: proxycli';

    public const string CaddyBindPlaceholder = '__ORBIT_PROXYCLI_BIND__';

    public const string CertificateCurrentDirectory = '/etc/caddy/orbit-proxycli-cert-current';

    public const string CaddyVersionsDirectory = '/etc/caddy/orbit-versions';

    public const string CaddyfilePath = '/etc/caddy/Caddyfile';

    public const string CaddyServiceName = 'caddy';

    public const string SourceDirectory = '/var/lib/orbit/proxycli';

    public const string SourcePath = '/var/lib/orbit/proxycli/server.py';
}
