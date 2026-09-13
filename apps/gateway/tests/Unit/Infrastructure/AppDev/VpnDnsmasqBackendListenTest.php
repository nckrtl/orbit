<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\VpnDnsmasqBackendListen;

it('moves a public orbit bind to the loopback backend without changing uplink records', function (): void {
    $configuration = <<<'CONF'
        # Managed by Orbit.
        interface=orbit
        interface=eth3
        bind-dynamic
        domain-needed
        no-resolv
        server=1.1.1.1
        host-record=gateway.orbit,10.44.0.1
        CONF;

    expect(VpnDnsmasqBackendListen::apply($configuration))
        ->toBe("# Managed by Orbit.\nlisten-address=127.0.0.54\nbind-interfaces\ndomain-needed\nno-resolv\nserver=1.1.1.1\nhost-record=gateway.orbit,10.44.0.1\n");
});

it('keeps an already backend fragment unchanged', function (): void {
    $configuration = "# Managed by Orbit.\nlisten-address=127.0.0.54\nbind-interfaces\nserver=8.8.8.8\n";

    expect(VpnDnsmasqBackendListen::apply($configuration))->toBe($configuration);
});
