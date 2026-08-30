<?php

declare(strict_types=1);

use App\Infrastructure\WireGuard\RetiredDnsmasqSnippets;
use Tests\Support\RetiredDnsmasqSnippetsHarness;

it('names the stock snippets it retires and the fixed directory outside the conf dir', function (): void {
    $snippets = new RetiredDnsmasqSnippets;

    expect(RetiredDnsmasqSnippets::NAMES)
        ->toBe(['ubuntu-fan'])
        ->and($snippets->path('ubuntu-fan'))
        ->toBe('/etc/dnsmasq.d/ubuntu-fan')
        ->and($snippets->retiredPath('ubuntu-fan'))
        ->toBe('/var/lib/orbit/dnsmasq/disabled/ubuntu-fan')
        ->and($snippets->arguments())
        ->toBe(['sudo', 'bash', '-seu', '--', '/etc/dnsmasq.d', '/var/lib/orbit/dnsmasq/disabled', 'ubuntu-fan'])
        ->and($snippets->invocation()->arguments)
        ->toBe($snippets->arguments())
        ->and($snippets->invocation()->input)
        ->toBe($snippets->script())
        ->and($snippets->invocation()->timeout)
        ->toBe(60.0);
});

it('moves each named snippet without deleting it or globbing unknown files', function (): void {
    $snippets = new RetiredDnsmasqSnippets;

    expect($snippets->script())
        ->toContain(
            'conf_directory=$1',
            'retired_directory=$2',
            'for snippet in "$@"; do',
            'stock=$conf_directory/$snippet',
            'if [ ! -e "$stock" ]; then',
            'install -d -o root -g root -m 0755 -- "$retired_directory"',
            'mv -fT -- "$stock" "$retired_directory/$snippet"',
        )
        ->not->toContain('rm ', '*');
});

it('runs no host command when no stock snippet is present', function (): void {
    $harness = new RetiredDnsmasqSnippetsHarness;

    try {
        $harness->put('orbit-vpn.conf', "bind-dynamic\n");

        expect($harness->run())
            ->toBe([0, []])
            ->and($harness->confDirectoryEntries())
            ->toBe(['orbit-vpn.conf'])
            ->and($harness->retiredDirectoryEntries())
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('moves the stock ubuntu-fan snippet out of the conf dir and leaves other files alone', function (): void {
    $harness = new RetiredDnsmasqSnippetsHarness;
    $fan = "# Ubuntu Fan\nbind-interfaces\nexcept-interface=fan-*\n";

    try {
        $harness->put('ubuntu-fan', $fan);
        $harness->put('orbit-vpn.conf', "bind-dynamic\n");
        [$exitCode, $commands] = $harness->run();

        expect($exitCode)
            ->toBe(0)
            ->and($commands)
            ->toBe([
                'install -d -o root -g root -m 0755 -- '.$harness->retiredDirectory(),
                'mv -fT -- '.$harness->confDirectory().'/ubuntu-fan '.$harness->retiredDirectory().'/ubuntu-fan',
            ])
            ->and($harness->confDirectoryEntries())
            ->toBe(['orbit-vpn.conf'])
            ->and($harness->retiredDirectoryEntries())
            ->toBe(['ubuntu-fan'])
            ->and($harness->contents($harness->retiredDirectory().'/ubuntu-fan'))
            ->toBe($fan);
    } finally {
        $harness->cleanup();
    }
});

it('is a no-op on a second run and keeps the retired snippet in place', function (): void {
    $harness = new RetiredDnsmasqSnippetsHarness;

    try {
        $harness->put('ubuntu-fan', "bind-interfaces\n");
        $harness->run();
        [$exitCode, $commands] = $harness->run();

        expect($exitCode)
            ->toBe(0)
            ->and($commands)
            ->toBeEmpty()
            ->and($harness->confDirectoryEntries())
            ->toBeEmpty()
            ->and($harness->contents($harness->retiredDirectory().'/ubuntu-fan'))
            ->toBe("bind-interfaces\n");
    } finally {
        $harness->cleanup();
    }
});
