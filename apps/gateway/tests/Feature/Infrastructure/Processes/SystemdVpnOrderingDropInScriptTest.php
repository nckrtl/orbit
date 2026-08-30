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

it('leaves the host untouched when no stale dnsmasq drop-in is installed', function (): void {
    $harness = new VpnOrderingDropInHarness;

    try {
        [$exitCode, $serviceCalls] = $harness->runRemoval('dnsmasq');

        expect($exitCode)
            ->toBe(0)
            ->and($serviceCalls)
            ->toBeEmpty()
            ->and($harness->installedContents('dnsmasq'))
            ->toBeNull();
    } finally {
        $harness->cleanup();
    }
});

it('removes a stale dnsmasq drop-in, reloads systemd, and restarts dnsmasq', function (): void {
    $harness = new VpnOrderingDropInHarness;

    try {
        $harness->run('dnsmasq');
        [$exitCode, $serviceCalls] = $harness->runRemoval('dnsmasq');

        expect($exitCode)
            ->toBe(0)
            ->and($harness->installedContents('dnsmasq'))
            ->toBeNull()
            ->and($serviceCalls)
            ->toBe(['daemon-reload', 'restart dnsmasq'])
            ->and(is_dir(dirname($harness->dropIn()->path('dnsmasq'))))
            ->toBeFalse();
    } finally {
        $harness->cleanup();
    }
});

it('removes a drifted stale drop-in and then stays quiet on the next convergence', function (): void {
    $harness = new VpnOrderingDropInHarness;

    try {
        $harness->run('dnsmasq');
        file_put_contents(filename: $harness->dropIn()->path('dnsmasq'), data: "[Unit]\n");
        [$firstExitCode, $firstServiceCalls] = $harness->runRemoval('dnsmasq');
        [$secondExitCode, $secondServiceCalls] = $harness->runRemoval('dnsmasq');

        expect($firstExitCode)
            ->toBe(0)
            ->and($firstServiceCalls)
            ->toBe(['daemon-reload', 'restart dnsmasq'])
            ->and($secondExitCode)
            ->toBe(0)
            ->and($secondServiceCalls)
            ->toBeEmpty()
            ->and($harness->installedContents('dnsmasq'))
            ->toBeNull();
    } finally {
        $harness->cleanup();
    }
});
