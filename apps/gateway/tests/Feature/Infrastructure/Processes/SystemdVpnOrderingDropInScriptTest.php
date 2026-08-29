<?php

declare(strict_types=1);

use Tests\Support\VpnOrderingDropInHarness;

it('installs the drop-in and reloads systemd the first time it lands', function (): void {
    $harness = new VpnOrderingDropInHarness;

    try {
        [$exitCode, $serviceCalls] = $harness->run('dnsmasq');

        expect($exitCode)
            ->toBe(0)
            ->and($harness->installedContents('dnsmasq'))
            ->toBe($harness->dropIn()->contents())
            ->and($serviceCalls)
            ->toBe(['daemon-reload', 'restart dnsmasq'])
            ->and($harness->leftovers('dnsmasq'))
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('does not reload or restart the unit when the installed drop-in already matches', function (): void {
    $harness = new VpnOrderingDropInHarness;

    try {
        $harness->run('caddy');
        [$exitCode, $serviceCalls] = $harness->run('caddy');

        expect($exitCode)
            ->toBe(0)
            ->and($harness->installedContents('caddy'))
            ->toBe($harness->dropIn()->contents())
            ->and($serviceCalls)
            ->toBe(['is-active --quiet caddy'])
            ->and($harness->leftovers('caddy'))
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('restarts an inactive unit without reloading when the drop-in is unchanged', function (): void {
    $harness = new VpnOrderingDropInHarness;

    try {
        $harness->run('caddy');
        [$exitCode, $serviceCalls] = $harness->run('caddy', serviceActive: false);

        expect($exitCode)
            ->toBe(0)
            ->and($serviceCalls)
            ->toBe(['is-active --quiet caddy', 'restart caddy']);
    } finally {
        $harness->cleanup();
    }
});

it('replaces a drifted drop-in and reloads systemd again', function (): void {
    $harness = new VpnOrderingDropInHarness;

    try {
        $harness->run('dnsmasq');
        file_put_contents(filename: $harness->dropIn()->path('dnsmasq'), data: "[Unit]\n");
        [$exitCode, $serviceCalls] = $harness->run('dnsmasq');

        expect($exitCode)
            ->toBe(0)
            ->and($harness->installedContents('dnsmasq'))
            ->toBe($harness->dropIn()->contents())
            ->and($serviceCalls)
            ->toBe(['daemon-reload', 'restart dnsmasq']);
    } finally {
        $harness->cleanup();
    }
});
