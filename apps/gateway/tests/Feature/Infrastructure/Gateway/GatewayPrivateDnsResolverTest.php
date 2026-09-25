<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;

describe('GatewayPrivateDnsResolver', function (): void {
    it('reapplies a suffix-only route to VPN DNS whenever the tunnel starts', function (): void {
        expect(new GatewayPrivateDnsResolver()->dropIn('10.44.0.1', 'orbit'))->toBe(implode("\n", [
            '# Managed by Orbit.',
            '[Service]',
            "ExecStartPost=-/bin/sh -c 'test -e /etc/wireguard/orbit.dns-link || { resolvectl dns orbit 10.44.0.1 && resolvectl domain orbit ~orbit; }'",
            '',
        ]));
    });

    it('installs the drop-in and applies the route to the live orbit link', function (): void {
        $resolver = new GatewayPrivateDnsResolver;
        $command = $resolver->convergeCommand('10.44.0.1', 'orbit');
        $encoded = base64_encode($resolver->dropIn('10.44.0.1', 'orbit'));

        expect($command->arguments)->toBe(['sudo', 'bash', '-seu', '--', GatewayPrivateDnsResolver::DROP_IN])
            ->and($command->input)->toContain(
                'exec 9>/run/lock/orbit-wireguard-peer.lock',
                "printf '%s' '{$encoded}' | base64 --decode > \"\$staged\"",
                'mv -fT -- "$candidate" "$managed"',
                'systemctl daemon-reload',
                'if [ -e /etc/wireguard/orbit.dns-link ] || ! ip link show dev orbit >/dev/null 2>&1; then',
                'resolvectl dns orbit 10.44.0.1',
                "resolvectl domain orbit '~orbit'",
            )
            ->and($command->input)->not->toContain('~.');
    });

    it('removes the drop-in and reverts only the link it configured', function (): void {
        $command = new GatewayPrivateDnsResolver()->removeCommand();

        expect($command->arguments)->toBe(['sudo', 'bash', '-seu', '--', GatewayPrivateDnsResolver::DROP_IN])
            ->and($command->input)->toContain(
                "if [ ! -e \"\$managed\" ]; then\n    exit 0",
                'rm -f -- "$managed"',
                'systemctl daemon-reload',
                'if [ -e /etc/wireguard/orbit.dns-link ] || ! ip link show dev orbit >/dev/null 2>&1; then',
                'resolvectl revert orbit',
            );
    });

    it('refuses an address or domain that is not a plain value', function (string $address, string $domain): void {
        expect(fn () => new GatewayPrivateDnsResolver()->convergeCommand($address, $domain))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)->toBe('gateway-private-dns-resolver')
                    ->and($exception->errorCode)->toBe('vpn.configuration_invalid');
            });
    })->with([
        'shell in the address' => ['10.44.0.1; true', 'orbit'],
        'shell in the domain' => ['10.44.0.1', "orbit'; true"],
        'empty domain' => ['10.44.0.1', ''],
    ]);
});
