<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\WebSocket\WebSocketHostname;

/**
 * Every trace the websocket role leaves on its node, and the proof Orbit owns each one.
 */
final readonly class WebSocketFootprint
{
    public const string Hostname = WebSocketHostname::Value;

    public const string ServiceName = 'orbit-websocket';

    public const string ServiceUnitPath = '/etc/systemd/system/orbit-websocket.service';

    public const string EnvironmentPath = '.env';

    /** The first line of the Caddy fragment, and the only proof that Orbit wrote it. */
    public const string CaddyFragment = 'websocket.caddy';

    public const string CaddyFragmentMarker = '# Managed by Orbit: websocket';

    /** Replaced on the node with the address its other Caddy sites already bind. */
    public const string CaddyBindPlaceholder = '__ORBIT_WEBSOCKET_BIND__';

    public const string CertificateVersionsDirectory = '/etc/caddy/orbit-websocket-cert-versions';

    public const string CertificateCurrentDirectory = '/etc/caddy/orbit-websocket-cert-current';

    public const string CertificateOwnershipMarker = 'websocket-certificate';

    public const string CaddyVersionsDirectory = '/etc/caddy/orbit-versions';

    public const string CaddyfilePath = '/etc/caddy/Caddyfile';

    public const string CaddyServiceName = 'caddy';

    public const string FirewallComment = 'orbit:websocket-https';

    public const string WireGuardInterface = 'orbit';

    public const string SettingKeyAppId = 'websocket.reverb.app_id';

    public const string SettingKeyAppKey = 'websocket.reverb.app_key';

    public const string SettingKeyAppSecret = 'websocket.reverb.app_secret';

    public const string SettingKeyAppKeyLaravel = 'websocket.reverb.laravel_app_key';
}
