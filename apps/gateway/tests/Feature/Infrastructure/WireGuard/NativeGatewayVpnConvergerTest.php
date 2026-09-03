<?php

declare(strict_types=1);

use App\Data\Gateway\BootstrapGatewayData;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Firewall\UfwStatusParser;
use App\Infrastructure\Firewall\UfwStoredRuleProbe;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\WireGuard\NativeGatewayVpnConverger;
use App\Infrastructure\WireGuard\RetiredDnsmasqSnippets;
use App\Infrastructure\WireGuard\UplinkDnsResolvers;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

it('activates the gateway WireGuard address through a validated atomic server config', function (): void {
    [$converger, $processes, $orbitHome] = gateway_vpn_converger();
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        $converger->converge($node, gateway_bootstrap_data());
        $arguments = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $call): array => $call->arguments)
            ->all();

        assert_gateway_generated_files(processes: $processes, orbitHome: $orbitHome);
        assert_gateway_publication_commands(
            processes: $processes,
            arguments: $arguments,
            orbitHome: $orbitHome,
        );
        assert_gateway_firewall_commands(arguments: $arguments);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

function assert_gateway_generated_files(GatewayVpnFakeProcessRunner $processes, string $orbitHome): void
{
    expect(file_get_contents($orbitHome.'/generated/wireguard/orbit.conf'))
        ->toContain(
            'Address = 10.44.0.1/24',
            'ListenPort = 51820',
            'PrivateKey = SERVER_PRIVATE',
        )
        ->not->toContain('[Peer]');

    expect(file_get_contents($orbitHome.'/generated/wireguard/90-orbit-forwarding.conf'))
        ->toBe("net.ipv4.ip_forward=1\n");
    expect($processes->observedProjectionLock)->toBeTrue();
    expect(file_get_contents($orbitHome.'/generated/dnsmasq/orbit-vpn.conf'))
        ->toBe(gateway_orbit_vpn_dnsmasq_conf())
        ->not->toContain('bind-interfaces', 'server=127.0.0.53');
}

/** @param list<list<string>> $arguments */
function assert_gateway_publication_commands(
    GatewayVpnFakeProcessRunner $processes,
    array $arguments,
    string $orbitHome,
): void {
    expect(array_slice(array: $arguments, offset: 0, length: 8))->toBe([
        [
            'sudo',
            'install',
            '-D',
            '-o',
            'root',
            '-g',
            'root',
            '-m',
            '0600',
            '--',
            $orbitHome.'/generated/wireguard/orbit.conf',
            '/etc/wireguard/orbit-candidate.conf',
        ],
        ['sudo', 'wg-quick', 'strip', '/etc/wireguard/orbit-candidate.conf'],
        [
            'sudo',
            'install',
            '-D',
            '-o',
            'root',
            '-g',
            'root',
            '-m',
            '0644',
            '--',
            $orbitHome.'/generated/wireguard/90-orbit-forwarding.conf',
            '/etc/sysctl.d/.90-orbit-wireguard-forwarding.conf.candidate',
        ],
        ['sudo', 'sysctl', '-p', '/etc/sysctl.d/.90-orbit-wireguard-forwarding.conf.candidate'],
        ['sudo', 'bash', '-seu'],
        [
            'sudo',
            'mv',
            '-f',
            '--',
            '/etc/sysctl.d/.90-orbit-wireguard-forwarding.conf.candidate',
            '/etc/sysctl.d/90-orbit-wireguard-forwarding.conf',
        ],
        [
            'sudo',
            'mv',
            '-f',
            '--',
            '/etc/wireguard/orbit-candidate.conf',
            '/etc/wireguard/orbit.conf',
        ],
        ['sudo', 'bash', '-seu'],
    ]);

    expect($processes->calls[4]->input)
        ->toContain('cp --preserve=mode,ownership -- "$live" "$backup"');
    expect($processes->calls[7]->input)
        ->toContain(
            'if ! systemctl restart wg-quick@orbit; then',
            'mv -fT -- "$backup" "$live"',
            'systemctl restart wg-quick@orbit || true',
        );
    expect($arguments[8])
        ->toBe(['sudo', 'bash', '-seu', '--', 'dnsmasq', '/etc/systemd/system']);
    expect($processes->calls[8]->input)
        ->toContain(
            'managed=$directory/orbit-vpn.conf',
            'if [ ! -e "$managed" ]; then',
            'rm -f -- "$managed"',
            'rmdir --ignore-fail-on-non-empty -- "$directory"',
            'systemctl daemon-reload',
            'systemctl restart "$service"',
        )
        ->not->toContain('After=wg-quick@orbit.service');
    expect($arguments[9])
        ->toBe([
            'sudo',
            'bash',
            '-seu',
            '--',
            '/etc/dnsmasq.d',
            '/var/lib/orbit/dnsmasq/disabled',
            'ubuntu-fan',
        ]);
    expect($processes->calls[9]->input)
        ->toBe(new RetiredDnsmasqSnippets()->script())
        ->toContain('mv -fT -- "$stock" "$retired_directory/$snippet"');
    expect($arguments[10])->toBe(['sudo', 'bash', '-seu']);
    expect($processes->calls[10]->input)
        ->toContain(
            'exec 9>/run/lock/orbit-dnsmasq.lock',
            'flock -w 30 9',
            'dnsmasq --test --conf-file="$validation/dnsmasq.conf"',
            'cmp -s -- "$validation/fragments/orbit-vpn.conf" "$managed"',
            'if systemctl is-active --quiet dnsmasq; then',
            'if ! restart_dnsmasq; then',
            'journalctl --unit=dnsmasq --lines=20 --no-pager >&2 || true',
            'systemctl restart dnsmasq || true',
        );
    expect(base64_decode(
        Str::match('/\x27([A-Za-z0-9+\/=]+)\x27 \| base64 --decode/', $processes->calls[10]->input ?? ''),
        strict: true,
    ))->toContain('bind-dynamic');
}

/** @param list<list<string>> $arguments */
function assert_gateway_firewall_commands(array $arguments): void
{
    expect(array_slice(array: $arguments, offset: 11))->toBe([
        ['sudo', 'ufw', 'status', 'numbered'],
        [
            'sudo',
            'ufw',
            'allow',
            'in',
            'proto',
            'udp',
            'to',
            'any',
            'port',
            '51820',
            'comment',
            'orbit:vpn-wireguard',
        ],
        [
            'sudo',
            'ufw',
            'allow',
            'in',
            'on',
            'orbit',
            'proto',
            'tcp',
            'to',
            '10.44.0.1',
            'port',
            '22',
            'comment',
            'orbit:vpn-ssh',
        ],
        [
            'sudo',
            'ufw',
            'allow',
            'in',
            'on',
            'orbit',
            'proto',
            'udp',
            'to',
            'any',
            'port',
            '53',
            'comment',
            'orbit:vpn-dns-udp-orbit',
        ],
        [
            'sudo',
            'ufw',
            'allow',
            'in',
            'on',
            'orbit',
            'proto',
            'tcp',
            'to',
            'any',
            'port',
            '53',
            'comment',
            'orbit:vpn-dns-tcp-orbit',
        ],
        [
            'sudo',
            'ufw',
            'allow',
            'in',
            'on',
            'orbit',
            'proto',
            'tcp',
            'to',
            'any',
            'port',
            '443',
            'comment',
            'orbit:gateway-https',
        ],
        [
            'sudo',
            'ufw',
            'route',
            'allow',
            'in',
            'on',
            'orbit',
            'out',
            'on',
            'orbit',
            'from',
            '10.44.0.0/24',
            'to',
            '10.44.0.0/24',
            'comment',
            'orbit:vpn-peer-forwarding',
        ],
        ['sudo', 'ufw', 'status', 'numbered'],
    ]);
}

it('does not reapply exact managed gateway firewall rules', function (): void {
    [$converger, $processes, $orbitHome] = gateway_vpn_converger(ufwRulesPreexisting: true);
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        $converger->converge($node, gateway_bootstrap_data());
        $ufwMutations = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $call): array => $call->arguments)
            ->filter(
                static fn (array $arguments): bool => (
                    array_slice(array: $arguments, offset: 0, length: 2) === ['sudo', 'ufw']
                    && array_slice(array: $arguments, offset: 0, length: 3) !== ['sudo', 'ufw', 'status']
                ),
            );

        $reappliedRules = $ufwMutations->filter(
            static fn (array $arguments): bool => (
                in_array(needle: 'comment', haystack: $arguments, strict: true)
                && ! in_array(needle: 'orbit:vpn-ssh', haystack: $arguments, strict: true)
            ),
        );

        expect($reappliedRules)->toBeEmpty();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('keeps SSH on WireGuard and removes public recovery after gateway VPN convergence', function (): void {
    [$converger, $processes, $orbitHome] = gateway_vpn_converger(ufwRulesPreexisting: true);
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        $converger->converge($node, gateway_bootstrap_data());
        $arguments = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $call): array => $call->arguments);

        expect($arguments)
            ->toContain(
                [
                    'sudo',
                    'ufw',
                    'allow',
                    'in',
                    'on',
                    'orbit',
                    'proto',
                    'tcp',
                    'to',
                    '10.44.0.1',
                    'port',
                    '22',
                    'comment',
                    'orbit:vpn-ssh',
                ],
                ['sudo', 'ufw', '--force', 'delete', '2'],
                ['sudo', 'ufw', '--force', 'delete', '1'],
            );
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('verifies public SSH recovery before enabling and converging an inactive UFW host', function (): void {
    [$converger, $processes, $orbitHome] = gateway_vpn_converger(ufwActive: false);
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        $converger->converge($node, gateway_bootstrap_data());
        $arguments = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $call): array => $call->arguments);
        $storedProbes = $arguments->filter(
            static fn (array $command): bool => $command === UfwStoredRuleProbe::arguments(),
        );

        expect($arguments->contains(['sudo', 'ufw', 'status', 'numbered']))
            ->toBeTrue()
            ->and($arguments->contains([
                'sudo',
                'ufw',
                'allow',
                'in',
                'proto',
                'tcp',
                'to',
                'any',
                'port',
                '22',
                'comment',
                'orbit:public-ssh-recovery',
            ]))
            ->toBeTrue()
            ->and($storedProbes)
            ->toHaveCount(2)
            ->and($arguments->contains(['sudo', 'ufw', '--force', 'enable']))
            ->toBeTrue()
            ->and($arguments->filter(
                static fn (array $command): bool => $command === ['sudo', 'ufw', 'status', 'numbered'],
            ))
            ->toHaveCount(4)
            ->and($arguments->contains(
                static fn (array $command): bool => (
                    array_slice(array: $command, offset: 0, length: 2) === ['sudo', 'ufw']
                    && in_array(needle: 'reset', haystack: $command, strict: true)
                ),
            ))
            ->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('refuses to enable inactive UFW when the protected stored recovery rule is missing', function (): void {
    [$converger, $processes, $orbitHome] = gateway_vpn_converger(
        ufwActive: false,
        missingStoredRecoveryRule: true,
    );
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        expect(fn () => $converger->converge($node, gateway_bootstrap_data()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('vpn-firewall-recovery-probe')
                    ->and($exception->errorCode)
                    ->toBe('vpn.firewall_recovery_probe_failed');
            });
        $arguments = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $call): array => $call->arguments);

        expect($arguments->contains(
            static fn (array $command): bool => (
                array_slice(array: $command, offset: 0, length: 2) === ['sudo', 'awk']
                && in_array(needle: '/etc/ufw/user.rules', haystack: $command, strict: true)
            ),
        ))
            ->toBeTrue()
            ->and($arguments->contains(['sudo', 'ufw', '--force', 'enable']))
            ->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('fails closed before mutating inactive UFW when the stored VPN comment has a broader shape', function (): void {
    [$converger, $processes, $orbitHome] = gateway_vpn_converger(
        ufwActive: false,
        storedRuleDrift: true,
    );
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        expect(fn () => $converger->converge($node, gateway_bootstrap_data()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('vpn-firewall-probe')
                    ->and($exception->errorCode)
                    ->toBe('vpn.firewall_probe_failed');
            });
        $mutations = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $call): array => $call->arguments)
            ->filter(
                static fn (array $arguments): bool => (
                    array_slice(array: $arguments, offset: 0, length: 2) === ['sudo', 'ufw']
                    && array_slice(array: $arguments, offset: 0, length: 3) !== ['sudo', 'ufw', 'status']
                ),
            );

        expect($mutations)->toBeEmpty();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('limits private DNS to the WireGuard and explicit private interfaces', function (): void {
    [$converger, $processes, $orbitHome] = gateway_vpn_converger();
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        $converger->converge($node, gateway_bootstrap_data('eth3'));
        $arguments = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $call): array => $call->arguments);

        expect(file_get_contents($orbitHome.'/generated/dnsmasq/orbit-vpn.conf'))
            ->toContain(
                "interface=orbit\n",
                "interface=eth3\n",
                'bind-dynamic',
                'local=/orbit/',
                'host-record=gateway.orbit,10.44.0.1',
            )
            ->not
            ->toContain('interface=lo', 'listen-address=0.0.0.0')
            ->and($arguments->contains([
                'sudo',
                'ufw',
                'allow',
                'in',
                'on',
                'eth3',
                'proto',
                'udp',
                'to',
                'any',
                'port',
                '53',
                'comment',
                'orbit:vpn-dns-udp-eth3',
            ]))
            ->toBeTrue()
            ->and($arguments->contains([
                'sudo',
                'ufw',
                'allow',
                'in',
                'on',
                'eth3',
                'proto',
                'tcp',
                'to',
                'any',
                'port',
                '53',
                'comment',
                'orbit:vpn-dns-tcp-eth3',
            ]))
            ->toBeTrue();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('fails when the active firewall does not expose every managed rule after convergence', function (): void {
    [$converger, , $orbitHome] = gateway_vpn_converger(incompleteUfwProbe: true);
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        expect(fn () => $converger->converge($node, gateway_bootstrap_data()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('vpn-firewall-probe')
                    ->and($exception->errorCode)
                    ->toBe('vpn.firewall_probe_failed');
            });
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('does not accept prefixed or suffixed firewall comments as managed rules', function (): void {
    [$converger, , $orbitHome] = gateway_vpn_converger(ambiguousUfwProbe: true);
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        expect(fn () => $converger->converge($node, gateway_bootstrap_data()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('vpn-firewall-probe')
                    ->and($exception->errorCode)
                    ->toBe('vpn.firewall_probe_failed');
            });
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('fails closed before mutating UFW when the VPN comment identifies a broader rule', function (): void {
    [$converger, $processes, $orbitHome] = gateway_vpn_converger(sameCommentWrongShape: true);
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        expect(fn () => $converger->converge($node, gateway_bootstrap_data()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('vpn-firewall-probe')
                    ->and($exception->errorCode)
                    ->toBe('vpn.firewall_probe_failed');
            });
        $ufwMutations = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $call): array => $call->arguments)
            ->filter(
                static fn (array $arguments): bool => (
                    array_slice(array: $arguments, offset: 0, length: 2) === ['sudo', 'ufw']
                    && array_slice(array: $arguments, offset: 0, length: 3) !== ['sudo', 'ufw', 'status']
                ),
            );

        expect($ufwMutations)->toBeEmpty();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('writes mesh dnsmasq upstreams from visible uplink resolvers', function (): void {
    [$converger, , $orbitHome] = gateway_vpn_converger();
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        gateway_write_host_file(
            $orbitHome,
            'run/systemd/resolve/resolv.conf',
            "nameserver 192.0.2.53\nnameserver 192.0.2.54\n",
        );
        $converger->converge($node, gateway_bootstrap_data());

        expect(file_get_contents($orbitHome.'/generated/dnsmasq/orbit-vpn.conf'))
            ->toBe(gateway_orbit_vpn_dnsmasq_conf(['192.0.2.53', '192.0.2.54']))
            ->not->toContain('server=1.1.1.1', 'server=8.8.8.8', 'server=127.0.0.53', 'bind-interfaces');
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('keeps the documented recursive fallback when only a stub resolver is visible', function (): void {
    [$converger, , $orbitHome] = gateway_vpn_converger();
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        gateway_write_host_file(
            $orbitHome,
            'run/systemd/resolve/resolv.conf',
            "nameserver 127.0.0.53\noptions edns0 trust-ad\n",
        );
        $converger->converge($node, gateway_bootstrap_data());

        expect(file_get_contents($orbitHome.'/generated/dnsmasq/orbit-vpn.conf'))
            ->toBe(gateway_orbit_vpn_dnsmasq_conf())
            ->not->toContain('server=127.0.0.53');
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('reports the bounded dnsmasq journal tail when the managed restart fails', function (): void {
    [$converger, $processes, $orbitHome] = gateway_vpn_converger();
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        $converger->converge($node, gateway_bootstrap_data());
        [$exitCode, $stderr] = gateway_dnsmasq_restart_failure($processes->calls[10]->input ?? '');

        expect($exitCode)
            ->toBe(1)
            ->and($stderr)
            ->toContain(
                'dnsmasq did not restart. Last journal lines:',
                'journalctl --unit=dnsmasq --lines=20 --no-pager',
                'dnsmasq: cannot set --bind-interfaces and --bind-dynamic',
            );
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('does not touch UFW when dnsmasq convergence fails and includes rollback', function (): void {
    [$converger, $processes, $orbitHome] = gateway_vpn_converger(failDns: true);
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        expect(fn () => $converger->converge($node, gateway_bootstrap_data()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('vpn-dns')
                    ->and($exception->errorCode)
                    ->toBe('vpn.dns_config_failed')
                    ->and($exception->result?->stderr)
                    ->toContain(
                        'dnsmasq did not restart. Last journal lines:',
                        'dnsmasq: cannot set --bind-interfaces and --bind-dynamic',
                    );
            });
        $dns = Collection::make($processes->calls)
            ->first(
                static fn (ProcessInvocation $call): bool => (
                    $call->arguments === ['sudo', 'bash', '-seu']
                    && str_contains($call->input ?? '', 'managed=/etc/dnsmasq.d/orbit-vpn.conf')
                ),
            );
        $arguments = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $call): array => $call->arguments);

        expect($dns)
            ->toBeInstanceOf(ProcessInvocation::class)
            ->and($dns?->input)
            ->toContain(
                'install -o root -g root -m 0644 -- "$backup" "$managed"',
                'rm -f -- "$managed"',
                'systemctl restart dnsmasq || true',
            )
            ->and($arguments->contains(['sudo', 'ufw', 'status', 'numbered']))
            ->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('does not replace or start the gateway WireGuard service when validation fails', function (): void {
    [$converger, $processes, $orbitHome] = gateway_vpn_converger(failValidation: true);
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        expect(fn () => $converger->converge($node, gateway_bootstrap_data()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('wireguard-server-validate')
                    ->and($exception->errorCode)
                    ->toBe('vpn.server_config_invalid');
            });
        $commands = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $call): array => $call->arguments);

        expect($commands->contains(
            static fn (array $arguments): bool => end($arguments) === '/etc/wireguard/orbit.conf',
        ))
            ->toBeFalse()
            ->and($commands->contains(['sudo', 'systemctl', 'restart', 'wg-quick@orbit']))
            ->toBeFalse()
            ->and($commands->contains([
                'sudo',
                'rm',
                '-f',
                '--',
                '/etc/wireguard/orbit-candidate.conf',
                '/etc/sysctl.d/.90-orbit-wireguard-forwarding.conf.candidate',
            ]))
            ->toBeTrue();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('keeps live VPN and forwarding configs untouched when forwarding validation fails', function (): void {
    [$converger, $processes, $orbitHome] = gateway_vpn_converger(failForwarding: true);
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        expect(fn () => $converger->converge($node, gateway_bootstrap_data()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('wireguard-forwarding-apply')
                    ->and($exception->errorCode)
                    ->toBe('vpn.forwarding_config_invalid');
            });
        $commands = Collection::make($processes->calls)
            ->map(static fn (ProcessInvocation $call): array => $call->arguments);

        expect($commands->contains([
            'sudo',
            'mv',
            '-f',
            '--',
            '/etc/wireguard/orbit-candidate.conf',
            '/etc/wireguard/orbit.conf',
        ]))
            ->toBeFalse()
            ->and($commands->contains([
                'sudo',
                'mv',
                '-f',
                '--',
                '/etc/sysctl.d/.90-orbit-wireguard-forwarding.conf.candidate',
                '/etc/sysctl.d/90-orbit-wireguard-forwarding.conf',
            ]))
            ->toBeFalse()
            ->and($commands->contains(['sudo', 'systemctl', 'restart', 'wg-quick@orbit']))
            ->toBeFalse()
            ->and($commands->contains([
                'sudo',
                'rm',
                '-f',
                '--',
                '/etc/wireguard/orbit-candidate.conf',
                '/etc/sysctl.d/.90-orbit-wireguard-forwarding.conf.candidate',
            ]))
            ->toBeTrue();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('restores and restarts the previous gateway WireGuard config when activation fails', function (): void {
    [$converger, $processes, $orbitHome] = gateway_vpn_converger(failServerRestart: true);
    $node = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
    ]);

    try {
        expect(fn () => $converger->converge($node, gateway_bootstrap_data()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('wireguard-server-restart')
                    ->and($exception->errorCode)
                    ->toBe('vpn.server_start_failed')
                    ->and($exception->result?->stderr)
                    ->toBe('new WireGuard config failed');
            });
        $scripts = Collection::make($processes->calls)
            ->filter(static fn (ProcessInvocation $call): bool => $call->arguments === ['sudo', 'bash', '-seu'])
            ->pluck('input')
            ->filter(static fn (mixed $input): bool => is_string($input))
            ->implode("\n");

        expect($scripts)
            ->toContain(
                'cp --preserve=mode,ownership -- "$live" "$backup"',
                'mv -fT -- "$backup" "$live"',
                'systemctl restart wg-quick@orbit || true',
            );
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

/**
 * @mago-expect lint:excessive-parameter-list Scenario flags keep each failure test explicit at the call site.
 *
 * @return array{NativeGatewayVpnConverger, GatewayVpnFakeProcessRunner, string}
 */
/**
 * Run the rendered dnsmasq restart helper with a failing `systemctl` shim and
 * a `journalctl` shim that prints the conflict the unit reports.
 *
 * @return array{int, string}
 */
function gateway_dnsmasq_restart_failure(string $script): array
{
    $root = sys_get_temp_dir().'/orbit-dnsmasq-restart-'.Str::uuid();
    mkdir(directory: $root.'/bin', permissions: 0o700, recursive: true);
    file_put_contents(
        filename: $root.'/bin/systemctl',
        data: "#!/usr/bin/env bash\nprintf 'Job for dnsmasq.service failed.\\n' >&2\nexit 1\n",
    );
    file_put_contents(filename: $root.'/bin/journalctl', data: <<<'BASH'
        #!/usr/bin/env bash
        printf 'journalctl %s\n' "$*"
        printf 'dnsmasq: cannot set --bind-interfaces and --bind-dynamic\n'
        BASH);
    chmod($root.'/bin/systemctl', permissions: 0o755);
    chmod($root.'/bin/journalctl', permissions: 0o755);
    $helper = Str::match('/^restart_dnsmasq\(\) \{$.*?^\}$/ms', $script);

    try {
        $process = new Symfony\Component\Process\Process(['bash', '-seu'], $root, [
            'PATH' => $root.'/bin:'.getenv('PATH'),
        ]);
        $process->setInput($helper."\nrestart_dnsmasq\n");
        $process->run();

        return [$process->getExitCode() ?? 1, $process->getErrorOutput()];
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
}

/** @param list<string> $servers */
function gateway_orbit_vpn_dnsmasq_conf(array $servers = UplinkDnsResolvers::FALLBACK): string
{
    $upstream = implode("\n", array_map(
        static fn (string $server): string => "server={$server}",
        $servers,
    ));

    return "# Managed by Orbit.\ninterface=orbit\nbind-dynamic\ndomain-needed\nbogus-priv\nno-resolv\n{$upstream}\nlocal=/orbit/\nhost-record=gateway.orbit,10.44.0.1\n";
}

function gateway_write_host_file(string $orbitHome, string $relative, string $contents): void
{
    $path = $orbitHome.'/host/'.$relative;
    new Filesystem()->ensureDirectoryExists(dirname($path));
    file_put_contents($path, $contents);
}

function gateway_vpn_converger(
    bool $failValidation = false,
    bool $failForwarding = false,
    bool $ufwActive = true,
    bool $failDns = false,
    bool $incompleteUfwProbe = false,
    bool $ambiguousUfwProbe = false,
    bool $failServerRestart = false,
    bool $missingStoredRecoveryRule = false,
    bool $sameCommentWrongShape = false,
    bool $ufwRulesPreexisting = false,
    bool $storedRuleDrift = false,
): array {
    $orbitHome = sys_get_temp_dir().'/orbit-gateway-vpn-'.Str::uuid();
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    mkdir(directory: $orbitHome.'/host', permissions: 0o700, recursive: true);
    file_put_contents(filename: $orbitHome.'/wireguard/private.key', data: 'SERVER_PRIVATE');
    file_put_contents(filename: $orbitHome.'/wireguard/public.key', data: 'SERVER_PUBLIC');
    $processes = new GatewayVpnFakeProcessRunner(
        failValidation: $failValidation,
        failForwarding: $failForwarding,
        ufwActive: $ufwActive,
        failDns: $failDns,
        incompleteUfwProbe: $incompleteUfwProbe,
        ambiguousUfwProbe: $ambiguousUfwProbe,
        failServerRestart: $failServerRestart,
        missingStoredRecoveryRule: $missingStoredRecoveryRule,
        sameCommentWrongShape: $sameCommentWrongShape,
        ufwRulesPreexisting: $ufwRulesPreexisting,
        storedRuleDrift: $storedRuleDrift,
        orbitHome: $orbitHome,
    );

    return [
        new NativeGatewayVpnConverger(
            renderer: new \App\Infrastructure\WireGuard\WireGuardServerConfigRenderer,
            files: new ProtectedFileWriter,
            processes: $processes,
            firewallParser: new UfwStatusParser,
            orbitHome: $orbitHome,
            uplinkResolvers: new UplinkDnsResolvers($orbitHome.'/host'),
        ),
        $processes,
        $orbitHome,
    ];
}

function gateway_bootstrap_data(?string $privateInterface = null): BootstrapGatewayData
{
    return new BootstrapGatewayData(
        publicHost: '85.9.218.89',
        wireguardIp: '10.44.0.1',
        wireguardSubnet: '10.44.0.0/24',
        wireguardEndpoint: '85.9.218.89:51820',
        dnsServer: '10.44.0.1',
        domain: 'orbit',
        privateInterface: $privateInterface,
    );
}

/**
 * @mago-expect lint:cyclomatic-complexity The fake models independent protected-host failure gates.
 * @mago-expect lint:file-name The test-local fake remains beside the interaction contract it supports.
 * @mago-expect lint:kan-defect The score reflects explicit independent failure scenarios in one fake adapter.
 */
final class GatewayVpnFakeProcessRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $calls = [];

    private int $ufwStatusCalls = 0;

    private int $serverRestartCalls = 0;

    private bool $ufwEnabled = false;

    /** @var array<string, true> */
    private array $ufwComments = [];

    public bool $observedProjectionLock = false;

    /** @mago-expect lint:excessive-parameter-list Scenario flags keep failure setup explicit. */
    public function __construct(
        private readonly bool $failValidation,
        private readonly bool $failForwarding,
        private readonly bool $ufwActive,
        private readonly bool $failDns,
        private readonly bool $incompleteUfwProbe,
        private readonly bool $ambiguousUfwProbe,
        private readonly bool $failServerRestart,
        private readonly bool $missingStoredRecoveryRule,
        private readonly bool $sameCommentWrongShape,
        private readonly bool $ufwRulesPreexisting,
        private readonly bool $storedRuleDrift,
        private readonly string $orbitHome,
    ) {
        if (! $this->ufwRulesPreexisting) {
            return;
        }

        $this->ufwComments = array_fill_keys([
            'orbit:public-ssh-recovery',
            'orbit:vpn-wireguard',
            'orbit:vpn-dns-udp-orbit',
            'orbit:vpn-dns-tcp-orbit',
            'orbit:vpn-dns-udp-eth3',
            'orbit:vpn-dns-tcp-eth3',
            'orbit:gateway-https',
            'orbit:vpn-peer-forwarding',
        ], value: true);
    }

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->calls[] = $invocation;
        $this->observeProjectionLock();

        $failure = $this->configuredFailure($invocation);

        if ($failure instanceof CommandResult) {
            return $failure;
        }

        return $this->firewallResult($invocation);
    }

    private function observeProjectionLock(): void
    {
        if (count($this->calls) !== 1) {
            return;
        }

        $lock = fopen($this->orbitHome.'/locks/wireguard-server.lock', mode: 'c+');

        if ($lock === false) {
            throw new RuntimeException('Could not inspect the WireGuard projection lock.');
        }

        $acquired = flock($lock, LOCK_EX | LOCK_NB);
        $this->observedProjectionLock = ! $acquired;

        if ($acquired) {
            flock($lock, LOCK_UN);
        }

        fclose($lock);
    }

    private function configuredFailure(ProcessInvocation $invocation): ?CommandResult
    {
        if (
            $this->failValidation
            && $invocation->arguments === ['sudo', 'wg-quick', 'strip', '/etc/wireguard/orbit-candidate.conf']
        ) {
            return new CommandResult(1, '', 'invalid WireGuard config', 2, false);
        }

        if (
            $this->failForwarding
            && $invocation->arguments === [
                'sudo',
                'sysctl',
                '-p',
                '/etc/sysctl.d/.90-orbit-wireguard-forwarding.conf.candidate',
            ]
        ) {
            return new CommandResult(1, '', 'invalid forwarding config', 2, false);
        }

        if (
            $this->failDns
            && $invocation->arguments === ['sudo', 'bash', '-seu']
            && str_contains($invocation->input ?? '', 'managed=/etc/dnsmasq.d/orbit-vpn.conf')
        ) {
            return new CommandResult(
                1,
                '',
                "dnsmasq did not restart. Last journal lines:\ndnsmasq: cannot set --bind-interfaces and --bind-dynamic\n",
                2,
                false,
            );
        }

        if (
            $this->failServerRestart
            && ($invocation->arguments === ['sudo', 'systemctl', 'restart', 'wg-quick@orbit']
            || $invocation->arguments === ['sudo', 'bash', '-seu']
            && str_contains($invocation->input ?? '', 'systemctl restart wg-quick@orbit')
            && ! str_contains($invocation->input ?? '', 'managed=/etc/dnsmasq.d/orbit-vpn.conf'))
        ) {
            $this->serverRestartCalls++;

            if ($this->serverRestartCalls === 1) {
                return new CommandResult(1, '', 'new WireGuard config failed', 2, false);
            }
        }

        return null;
    }

    private function firewallResult(ProcessInvocation $invocation): CommandResult
    {
        if ($invocation->arguments === ['sudo', 'ufw', '--force', 'enable']) {
            $this->ufwEnabled = true;

            return new CommandResult(0, 'Firewall is active and enabled on system startup', '', 2, false);
        }

        $this->recordFirewallComment($invocation);

        if (
            array_slice(array: $invocation->arguments, offset: 0, length: 2) === ['sudo', 'awk']
            && in_array(needle: '/etc/ufw/user.rules', haystack: $invocation->arguments, strict: true)
        ) {
            return new CommandResult(0, $this->storedRules(), '', 2, false);
        }

        if ($invocation->arguments !== ['sudo', 'ufw', 'status', 'numbered']) {
            return new CommandResult(0, '', '', 2, false);
        }

        $this->ufwStatusCalls++;
        $active = $this->ufwActive || $this->ufwEnabled;
        $status = $active ? 'active' : 'inactive';
        $comments = $active ? $this->activeFirewallRules() : '';

        return new CommandResult(0, "Status: {$status}\n{$comments}", '', 2, false);
    }

    private function recordFirewallComment(ProcessInvocation $invocation): void
    {
        if (
            array_slice(array: $invocation->arguments, offset: 0, length: 4) === ['sudo', 'ufw', '--force', 'delete']
        ) {
            unset($this->ufwComments['orbit:public-ssh-recovery']);

            return;
        }

        if (array_slice(array: $invocation->arguments, offset: 0, length: 2) === ['sudo', 'ufw']) {
            $commentIndex = array_search(needle: 'comment', haystack: $invocation->arguments, strict: true);
            $comment = is_int($commentIndex) ? $invocation->arguments[$commentIndex + 1] ?? null : null;

            if (is_string($comment)) {
                $this->ufwComments[$comment] = true;
            }
        }
    }

    private function storedRules(): string
    {
        $rules = [];

        if (array_key_exists('orbit:public-ssh-recovery', $this->ufwComments) && ! $this->missingStoredRecoveryRule) {
            $recovery = bin2hex('orbit:public-ssh-recovery');
            $rules[] = "__orbit_ufw_tuple:v4:### tuple ### allow tcp 22 0.0.0.0/0 any 0.0.0.0/0 in comment={$recovery}";
            $rules[] = "__orbit_ufw_tuple:v6:### tuple ### allow tcp 22 ::/0 any ::/0 in comment={$recovery}";
        }

        if ($this->storedRuleDrift) {
            $comment = bin2hex('orbit:vpn-wireguard');
            $rules[] = "__orbit_ufw_tuple:v4:### tuple ### allow udp 1:65535 0.0.0.0/0 any 0.0.0.0/0 in comment={$comment}";
            $rules[] = "__orbit_ufw_tuple:v6:### tuple ### allow udp 1:65535 ::/0 any ::/0 in comment={$comment}";
        }

        return implode("\n", $rules);
    }

    private function activeFirewallRules(): string
    {
        $comments = $this->ufwComments;
        $lines = [];

        if (array_key_exists('orbit:public-ssh-recovery', $comments)) {
            $lines[] = '[ 1] 22/tcp                     ALLOW IN    Anywhere                   # orbit:public-ssh-recovery';
            $lines[] = '[ 2] 22/tcp (v6)                ALLOW IN    Anywhere (v6)              # orbit:public-ssh-recovery';
        }

        if ($this->sameCommentWrongShape) {
            $lines[] = '[ 3] 1:65535/udp                ALLOW IN    Anywhere                   # orbit:vpn-wireguard';
            $lines[] = '[ 4] 1:65535/udp (v6)           ALLOW IN    Anywhere (v6)              # orbit:vpn-wireguard';
        }

        if (! $this->sameCommentWrongShape && array_key_exists('orbit:vpn-wireguard', $comments)) {
            $suffix = $this->ambiguousUfwProbe ? '-extra' : '';
            $lines[] = "[ 3] 51820/udp                  ALLOW IN    Anywhere                   # orbit:vpn-wireguard{$suffix}";
            $lines[] = "[ 4] 51820/udp (v6)             ALLOW IN    Anywhere (v6)              # orbit:vpn-wireguard{$suffix}";
        }

        if (array_key_exists('orbit:vpn-ssh', $comments)) {
            $lines[] = '[ 5] 10.44.0.1 22/tcp on orbit ALLOW IN Anywhere # orbit:vpn-ssh';
        }

        foreach ([
            'orbit:vpn-dns-udp-orbit' => ['53/udp on orbit', '53/udp (v6) on orbit'],
            'orbit:vpn-dns-tcp-orbit' => ['53/tcp on orbit', '53/tcp (v6) on orbit'],
            'orbit:vpn-dns-udp-eth3' => ['53/udp on eth3', '53/udp (v6) on eth3'],
            'orbit:vpn-dns-tcp-eth3' => ['53/tcp on eth3', '53/tcp (v6) on eth3'],
            'orbit:gateway-https' => ['443/tcp on orbit', '443/tcp (v6) on orbit'],
        ] as $comment => [$ipv4, $ipv6]) {
            if (! array_key_exists($comment, $comments)) {
                continue;
            }

            $lines[] = sprintf('[10] %-28s ALLOW IN    Anywhere                   # %s', $ipv4, $comment);
            $lines[] = sprintf('[11] %-28s ALLOW IN    Anywhere (v6)              # %s', $ipv6, $comment);
        }

        if (array_key_exists('orbit:vpn-peer-forwarding', $comments) && ! $this->incompleteUfwProbe) {
            $comment = $this->ambiguousUfwProbe
                ? 'orbit:vpn-peer-forwarding-extra'
                : 'orbit:vpn-peer-forwarding';
            $lines[] = "[20] 10.44.0.0/24 on orbit     ALLOW FWD   10.44.0.0/24 on orbit     # {$comment}";
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }
}
