<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\PrivateDnsListenerUnitRenderer;

it('renders a service that runs the installed release on the sockets of its socket unit', function (): void {
    $unit = new PrivateDnsListenerUnitRenderer()->render(
        phpBinary: '/usr/bin/php8.5',
        releaseDirectory: '/var/lib/orbit/private-dns/releases/0123456789abcdef',
        listenAddress: '10.44.0.1',
        port: 53,
        catalogPath: '/var/lib/orbit/private-dns/catalog.json',
        upstream: '127.0.0.55:53',
    );

    expect($unit)
        ->toContain('Description=Orbit private DNS')
        ->toContain('Requires=wg-quick@orbit.service orbit-private-dns.socket')
        ->toContain('After=network-online.target wg-quick@orbit.service dnsmasq.service orbit-private-dns.socket')
        ->toContain('WorkingDirectory=/var/lib/orbit/private-dns/releases/0123456789abcdef')
        ->toContain('"/usr/bin/php8.5" "/var/lib/orbit/private-dns/releases/0123456789abcdef/serve.php" "--listen=10.44.0.1" "--port=53" "--catalog=/var/lib/orbit/private-dns/catalog.json" "--upstream=127.0.0.55:53"')
        ->toContain('Sockets=orbit-private-dns.socket')
        ->not->toContain('ORBIT_HOME')
        ->and(new PrivateDnsListenerUnitRenderer()->path())
        ->toBe('/etc/systemd/system/orbit-private-dns.service');
});

it('renders a socket unit that holds the UDP and TCP address across listener restarts', function (): void {
    $socket = new PrivateDnsListenerUnitRenderer()->renderSocket('10.44.0.1', 53);

    expect($socket)
        ->toContain('ListenDatagram=10.44.0.1:53')
        ->toContain('ListenStream=10.44.0.1:53')
        ->toContain('FreeBind=yes')
        ->toContain('Service=orbit-private-dns.service')
        ->toContain('WantedBy=sockets.target')
        ->not->toContain('wg-quick')
        ->not->toContain('After=')
        ->not->toContain('DefaultDependencies=no')
        ->and(new PrivateDnsListenerUnitRenderer()->socketPath())
        ->toBe('/etc/systemd/system/orbit-private-dns.socket');
});
