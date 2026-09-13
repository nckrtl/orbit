<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\PrivateDnsListenerUnitRenderer;

it('renders a systemd unit that binds the WireGuard DNS address to the published catalog', function (): void {
    $unit = new PrivateDnsListenerUnitRenderer()->render(
        phpBinary: '/usr/bin/php8.5',
        artisan: '/home/orbit/orbit-gateway/artisan',
        listenAddress: '10.44.0.1',
        port: 53,
        catalogPath: '/var/lib/orbit/private-dns/catalog.json',
        upstream: '127.0.0.54:53',
        orbitHome: '/home/orbit/.orbit',
        workingDirectory: '/home/orbit/orbit-gateway',
    );

    expect($unit)
        ->toContain('Description=Orbit private DNS')
        ->toContain('Requires=wg-quick@orbit.service')
        ->toContain('After=network-online.target wg-quick@orbit.service dnsmasq.service')
        ->toContain('WorkingDirectory=/home/orbit/orbit-gateway')
        ->toContain('Environment=ORBIT_HOME=/home/orbit/.orbit')
        ->toContain('"/usr/bin/php8.5" "/home/orbit/orbit-gateway/artisan" "orbit:private-dns-serve" "--listen=10.44.0.1" "--port=53" "--catalog=/var/lib/orbit/private-dns/catalog.json" "--upstream=127.0.0.54:53"')
        ->and(new PrivateDnsListenerUnitRenderer()->path())
        ->toBe('/etc/systemd/system/orbit-private-dns.service');
});
