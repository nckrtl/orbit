<?php

declare(strict_types=1);

use App\Infrastructure\Processes\SystemdVpnOrderingDropIn;
use Illuminate\Support\Str;

it('renders the managed unit ordering drop-in for a VPN bound service', function (): void {
    $dropIn = new SystemdVpnOrderingDropIn;

    expect($dropIn->contents())
        ->toBe("# Managed by Orbit.\n[Unit]\nAfter=wg-quick@orbit.service\nWants=wg-quick@orbit.service\n")
        ->and($dropIn->path('dnsmasq'))
        ->toBe('/etc/systemd/system/dnsmasq.service.d/orbit-vpn.conf')
        ->and($dropIn->path('caddy'))
        ->toBe('/etc/systemd/system/caddy.service.d/orbit-vpn.conf')
        ->and($dropIn->arguments('caddy'))
        ->toBe(['sudo', 'bash', '-seu', '--', 'caddy', '/etc/systemd/system']);
});

it('installs the drop-in atomically and only converges the unit when the content differs', function (): void {
    $dropIn = new SystemdVpnOrderingDropIn;
    $script = $dropIn->script();

    expect($script)
        ->toContain(
            'directory=$unit_directory/$service.service.d',
            'managed=$directory/orbit-vpn.conf',
            'candidate=$directory/.orbit-vpn.conf.$$.candidate',
            'trap \'rm -f -- "$staged" "$candidate"\' EXIT',
            'install -d -o root -g root -m 0755 -- "$directory"',
            'if [ -f "$managed" ] && cmp -s -- "$staged" "$managed"; then',
            'if systemctl is-active --quiet "$service"; then',
            'install -o root -g root -m 0644 -- "$staged" "$candidate"',
            'mv -fT -- "$candidate" "$managed"',
            'systemctl daemon-reload',
            'systemctl restart "$service"',
        )
        ->and(base64_decode(
            Str::match('/\x27([A-Za-z0-9+\/=]+)\x27 \| base64 --decode/', $script),
            strict: true,
        ))
        ->toBe($dropIn->contents());
});

it('honours an alternative unit directory for both the path and the script arguments', function (): void {
    $dropIn = new SystemdVpnOrderingDropIn('/tmp/orbit-units');

    expect($dropIn->path('dnsmasq'))
        ->toBe('/tmp/orbit-units/dnsmasq.service.d/orbit-vpn.conf')
        ->and($dropIn->arguments('dnsmasq'))
        ->toBe(['sudo', 'bash', '-seu', '--', 'dnsmasq', '/tmp/orbit-units'])
        ->and($dropIn->invocation('dnsmasq')->arguments)
        ->toBe(['sudo', 'bash', '-seu', '--', 'dnsmasq', '/tmp/orbit-units'])
        ->and($dropIn->invocation('dnsmasq')->input)
        ->toBe($dropIn->script())
        ->and($dropIn->invocation('dnsmasq')->timeout)
        ->toBe(60.0);
});

it('renders a removal script that only converges the unit when a stale drop-in existed', function (): void {
    $dropIn = new SystemdVpnOrderingDropIn;
    $script = $dropIn->removalScript();

    expect($script)
        ->toContain(
            'directory=$unit_directory/$service.service.d',
            'managed=$directory/orbit-vpn.conf',
            'if [ ! -e "$managed" ]; then',
            'rm -f -- "$managed"',
            'rmdir --ignore-fail-on-non-empty -- "$directory"',
            'systemctl daemon-reload',
            'systemctl restart "$service"',
        )
        ->and($dropIn->removalArguments('dnsmasq'))
        ->toBe(['sudo', 'bash', '-seu', '--', 'dnsmasq', '/etc/systemd/system'])
        ->and($dropIn->removalInvocation('dnsmasq')->arguments)
        ->toBe(['sudo', 'bash', '-seu', '--', 'dnsmasq', '/etc/systemd/system'])
        ->and($dropIn->removalInvocation('dnsmasq')->input)
        ->toBe($script)
        ->and($dropIn->removalInvocation('dnsmasq')->timeout)
        ->toBe(60.0);
});
