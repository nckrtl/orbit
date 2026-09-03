<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    Process::fake();
});

function lifecycleHost(string $remote = 'local'): IncusHost
{
    return new IncusHost($remote, 'orbit', 'orbit-e2e');
}

/** @return list<string> */
function lifecycleIncus(string ...$arguments): array
{
    return ['incus', '--project', 'orbit', ...$arguments];
}

/** @return list<string> */
function lifecycleFirewallHelper(): array
{
    return [
        'python3',
        dirname(__DIR__, 3).'/resources/host/reconcile-firewall.py',
    ];
}

function lifecycleDnsmasq(): string
{
    return 'port=0';
}

/** @mago-expect lint:cyclomatic-complexity,kan-defect Exact process cases share one lifecycle boundary. */
describe('IncusNetworkLifecycle', function (): void {
    it('uses one owned host firewall transaction for network setup', function (): void {
        $firewallRequests = [];

        Process::fake(function (PendingProcess $process) use (&$firewallRequests) {
            if (
                $process->command === lifecycleIncus(
                    'network',
                    'create',
                    'local:oe-nck-123',
                    'ipv4.address=10.232.2.1/24',
                    'ipv4.nat=true',
                    'ipv4.dhcp.ranges=10.232.2.10-10.232.2.12',
                    'ipv6.address=none',
                    'raw.dnsmasq='.lifecycleDnsmasq(),
                    'user.orbit.e2e.owner=orbit-e2e',
                )
            ) {
                return Process::result();
            }
            if ($process->command === lifecycleFirewallHelper()) {
                $firewallRequests[] = json_decode((string) $process->input, true, 512, JSON_THROW_ON_ERROR);

                return Process::result("{\"changed\":true}\n");
            }

            return Process::result('', 'Unexpected command.', 2);
        });

        new IncusNetworkLifecycle(lifecycleHost())->create('oe-nck-123', 2);

        expect($firewallRequests)->toBe([[
            'operation' => 'ensure',
            'network' => 'oe-nck-123',
            'managed_interface_pattern' => 'oe+',
            'owner' => 'orbit-e2e',
        ]]);
        Process::assertRanTimes(lifecycleFirewallHelper(), 1);
        Process::assertNotRan(fn (PendingProcess $process): bool => ($process->command[2] ?? null) === 'iptables');
    });

    it('creates an IPv4 network before installing owned forwarding rules', function (): void {
        Process::fake(function (PendingProcess $process) {
            if (
                $process->command === lifecycleIncus(
                    'network',
                    'create',
                    'local:oe-nck-123',
                    'ipv4.address=10.232.2.1/24',
                    'ipv4.nat=true',
                    'ipv4.dhcp.ranges=10.232.2.10-10.232.2.12',
                    'ipv6.address=none',
                    'raw.dnsmasq='.lifecycleDnsmasq(),
                    'user.orbit.e2e.issue=NCK-123',
                    'user.orbit.e2e.operation=0123456789abcdef0123456789abcdef',
                    'user.orbit.e2e.owner=orbit-e2e',
                )
            ) {
                return Process::result();
            }
            if ($process->command === lifecycleFirewallHelper()) {
                return Process::result("{\"changed\":true}\n");
            }

            return Process::result('', 'Unexpected command.', 2);
        });

        $network = new IncusNetworkLifecycle(lifecycleHost())->create('oe-nck-123', 2, [
            'user.orbit.e2e.issue' => 'NCK-123',
            'user.orbit.e2e.operation' => '0123456789abcdef0123456789abcdef',
        ]);

        expect($network->metadata)->toBe([
            'user.orbit.e2e.issue' => 'NCK-123',
            'user.orbit.e2e.operation' => '0123456789abcdef0123456789abcdef',
            'user.orbit.e2e.owner' => 'orbit-e2e',
        ]);

        Process::assertRanInOrder([
            lifecycleIncus(
                'network',
                'create',
                'local:oe-nck-123',
                'ipv4.address=10.232.2.1/24',
                'ipv4.nat=true',
                'ipv4.dhcp.ranges=10.232.2.10-10.232.2.12',
                'ipv6.address=none',
                'raw.dnsmasq='.lifecycleDnsmasq(),
                'user.orbit.e2e.issue=NCK-123',
                'user.orbit.e2e.operation=0123456789abcdef0123456789abcdef',
                'user.orbit.e2e.owner=orbit-e2e',
            ),
            lifecycleFirewallHelper(),
        ]);
    });

    it('extends the DHCP range through the recipe final address', function (): void {
        Process::fake(function (PendingProcess $process) {
            if ($process->command === lifecycleFirewallHelper()) {
                return Process::result("{\"changed\":true}\n");
            }

            return Process::result();
        });

        new IncusNetworkLifecycle(lifecycleHost())->create('oe-nck-123', 2, lastAddress: 13);

        Process::assertRan(lifecycleIncus(
            'network',
            'create',
            'local:oe-nck-123',
            'ipv4.address=10.232.2.1/24',
            'ipv4.nat=true',
            'ipv4.dhcp.ranges=10.232.2.10-10.232.2.13',
            'ipv6.address=none',
            'raw.dnsmasq='.lifecycleDnsmasq(),
            'user.orbit.e2e.owner=orbit-e2e',
        ));
    });

    it('rolls back when the firewall helper returns invalid output', function (): void {
        $helperCalls = 0;

        Process::fake(function (PendingProcess $process) use (&$helperCalls) {
            if (
                $process->command === lifecycleIncus(
                    'network',
                    'create',
                    'local:oe-nck-123',
                    'ipv4.address=10.232.2.1/24',
                    'ipv4.nat=true',
                    'ipv4.dhcp.ranges=10.232.2.10-10.232.2.12',
                    'ipv6.address=none',
                    'raw.dnsmasq='.lifecycleDnsmasq(),
                    'user.orbit.e2e.owner=orbit-e2e',
                )
            ) {
                return Process::result();
            }
            if ($process->command === lifecycleFirewallHelper()) {
                $helperCalls++;

                return $helperCalls === 1
                    ? Process::result('{}')
                    : Process::result("{\"changed\":false}\n");
            }
            if ($process->command === lifecycleIncus('network', 'list', 'local:', '--format=json')) {
                return Process::result(json_encode([[
                    'name' => 'oe-nck-123',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }
            if ($process->command === lifecycleIncus('network', 'delete', 'local:oe-nck-123')) {
                return Process::result();
            }

            return Process::result('', 'Unexpected command.', 2);
        });

        expect(fn () => new IncusNetworkLifecycle(lifecycleHost())->create('oe-nck-123', 2))
            ->toThrow(RuntimeException::class, 'Host firewall helper returned invalid output.');
        expect($helperCalls)->toBe(2);
        Process::assertRan(lifecycleIncus('network', 'delete', 'local:oe-nck-123'));
    });

    it('rolls back the network when forwarding setup fails', function (): void {
        $commands = [];
        $helperCalls = 0;
        $networkLists = 0;

        Process::fake(function (PendingProcess $process) use (&$commands, &$helperCalls, &$networkLists) {
            $commands[] = $process->command;
            if (
                $process->command === lifecycleIncus(
                    'network',
                    'create',
                    'local:oe-nck-123',
                    'ipv4.address=10.232.2.1/24',
                    'ipv4.nat=true',
                    'ipv4.dhcp.ranges=10.232.2.10-10.232.2.12',
                    'ipv6.address=none',
                    'raw.dnsmasq='.lifecycleDnsmasq(),
                    'user.orbit.e2e.owner=orbit-e2e',
                )
            ) {
                return Process::result();
            }
            if ($process->command === lifecycleFirewallHelper()) {
                $helperCalls++;

                return $helperCalls === 1
                    ? Process::result('', 'setup failed', 2)
                    : Process::result("{\"changed\":true}\n");
            }
            if ($process->command === lifecycleIncus('network', 'list', 'local:', '--format=json')) {
                $networkLists++;

                return Process::result(
                    $networkLists === 1
                        ? json_encode([
                            ['name' => 'oe-nck-123', 'config' => ['user.orbit.e2e.owner' => 'orbit-e2e']],
                        ], JSON_THROW_ON_ERROR) : '[]',
                );
            }
            if ($process->command === lifecycleIncus('network', 'delete', 'local:oe-nck-123')) {
                return Process::result();
            }

            return Process::result('', 'Unexpected command.', 2);
        });

        expect(fn () => new IncusNetworkLifecycle(lifecycleHost())->create('oe-nck-123', 2))
            ->toThrow(RuntimeException::class, 'Host firewall command failed: setup failed');
        expect($helperCalls)->toBe(2);
        expect($commands)->toContain(lifecycleIncus('network', 'delete', 'local:oe-nck-123'));
    });

    it('reports recovery when rollback fails', function (): void {
        $helperCalls = 0;
        Process::fake(function (PendingProcess $process) use (&$helperCalls) {
            if (str_starts_with(implode(' ', $process->command), 'incus --project orbit network create')) {
                return Process::result();
            }
            if ($process->command === lifecycleFirewallHelper()) {
                $helperCalls++;

                return $helperCalls === 1
                    ? Process::result('', 'setup failed', 2)
                    : Process::result("{\"changed\":true}\n");
            }
            if ($process->command === lifecycleIncus('network', 'list', 'local:', '--format=json')) {
                return Process::result(json_encode([[
                    'name' => 'oe-nck-123',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }
            if ($process->command === lifecycleIncus('network', 'delete', 'local:oe-nck-123')) {
                return Process::result('', 'delete failed', 2);
            }

            return Process::result('', 'Unexpected command.', 2);
        });

        $exception = null;
        try {
            new IncusNetworkLifecycle(lifecycleHost())->create('oe-nck-123', 2);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        expect($exception)
            ->toBeInstanceOf(RuntimeException::class)
            ->and($exception?->getMessage())
            ->toContain('manual recovery is required')
            ->and($exception?->getMessage())
            ->toContain('delete failed')
            ->and($exception?->getPrevious()?->getMessage())
            ->toBe('Host firewall command failed: setup failed');
        Process::assertRan(lifecycleIncus('network', 'delete', 'local:oe-nck-123'));
    });

    it('retains the network as a recovery anchor when rule cleanup fails', function (): void {
        Process::fake(function (PendingProcess $process) {
            if (str_starts_with(implode(' ', $process->command), 'incus --project orbit network create')) {
                return Process::result();
            }
            if ($process->command === lifecycleFirewallHelper()) {
                return Process::result('', 'inspection failed', 2);
            }

            return Process::result('', 'Unexpected command.', 2);
        });

        $exception = null;
        try {
            new IncusNetworkLifecycle(lifecycleHost())->create('oe-nck-123', 2);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        expect($exception)
            ->toBeInstanceOf(RuntimeException::class)
            ->and($exception?->getMessage())
            ->toContain('manual recovery is required')
            ->and($exception?->getMessage())
            ->toContain('rollback failed')
            ->and($exception?->getPrevious()?->getMessage())
            ->toBe('Host firewall command failed: inspection failed');
        Process::assertNotRan(lifecycleIncus('network', 'delete', 'local:oe-nck-123'));
    });

    it('reconciles forwarding for an existing owned network', function (): void {
        $networkLists = 0;

        Process::fake(function (PendingProcess $process) use (&$networkLists) {
            if ($process->command === lifecycleIncus('network', 'list', 'local:', '--format=json')) {
                $networkLists++;

                return Process::result(json_encode([[
                    'name' => 'oe-nck-123',
                    'config' => $networkLists === 1
                        ? ['user.orbit.e2e.owner' => 'orbit-e2e', 'ipv4.address' => '10.232.2.1/24']
                        : [
                            'user.orbit.e2e.owner' => 'orbit-e2e',
                            'ipv4.address' => '10.232.2.1/24',
                            'ipv4.nat' => 'true',
                            'ipv4.dhcp.ranges' => '10.232.2.10-10.232.2.12',
                            'ipv6.address' => 'none',
                            'raw.dnsmasq' => lifecycleDnsmasq(),
                        ],
                ]], JSON_THROW_ON_ERROR));
            }

            if ($process->command === lifecycleFirewallHelper()) {
                return Process::result("{\"changed\":true}\n");
            }

            if (
                $process->command === lifecycleIncus(
                    'network',
                    'set',
                    'local:oe-nck-123',
                    'ipv4.nat=true',
                    'ipv4.dhcp.ranges=10.232.2.10-10.232.2.12',
                    'ipv6.address=none',
                    'raw.dnsmasq='.lifecycleDnsmasq(),
                )
            ) {
                return Process::result();
            }

            return Process::result('', 'Unexpected command.', 2);
        });

        $network = new IncusNetworkLifecycle(lifecycleHost())->reconcile('oe-nck-123');
        expect($network->config)->toMatchArray([
            'ipv4.address' => '10.232.2.1/24',
            'ipv4.nat' => 'true',
            'ipv4.dhcp.ranges' => '10.232.2.10-10.232.2.12',
            'ipv6.address' => 'none',
            'raw.dnsmasq' => lifecycleDnsmasq(),
        ]);

        Process::assertNotRan(fn (PendingProcess $process): bool => ($process->command[3] ?? null) === 'create');
        Process::assertRanTimes(lifecycleFirewallHelper(), 1);
        Process::assertRanTimes(lifecycleIncus(
            'network',
            'set',
            'local:oe-nck-123',
            'ipv4.nat=true',
            'ipv4.dhcp.ranges=10.232.2.10-10.232.2.12',
            'ipv6.address=none',
            'raw.dnsmasq='.lifecycleDnsmasq(),
        ), 1);
        Process::assertNotRan(lifecycleIncus('network', 'set', 'local:oe-nck-123', 'ipv4.address', 'auto'));
    });

    it('does not rewrite unchanged network configuration', function (): void {
        Process::fake(function (PendingProcess $process) {
            if ($process->command === lifecycleIncus('network', 'list', 'local:', '--format=json')) {
                return Process::result(json_encode([[
                    'name' => 'oe-nck-123',
                    'config' => [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'ipv4.address' => '10.232.2.1/24',
                        'ipv4.nat' => 'true',
                        'ipv4.dhcp.ranges' => '10.232.2.10-10.232.2.12',
                        'ipv6.address' => 'none',
                        'raw.dnsmasq' => lifecycleDnsmasq(),
                    ],
                ]], JSON_THROW_ON_ERROR));
            }

            if ($process->command === lifecycleFirewallHelper()) {
                return Process::result("{\"changed\":false}\n");
            }

            return Process::result('', 'Unexpected command.', 2);
        });

        $network = new IncusNetworkLifecycle(lifecycleHost())->reconcile('oe-nck-123');

        expect($network->config)->toMatchArray([
            'ipv4.nat' => 'true',
            'ipv4.dhcp.ranges' => '10.232.2.10-10.232.2.12',
            'ipv6.address' => 'none',
            'raw.dnsmasq' => lifecycleDnsmasq(),
        ]);
        Process::assertRanTimes(lifecycleIncus('network', 'list', 'local:', '--format=json'), 1);
        Process::assertRanTimes(lifecycleFirewallHelper(), 1);
        Process::assertNotRan(fn (PendingProcess $process): bool => ($process->command[4] ?? null) === 'set');
    });

    it('blocks configuration mutation for a foreign network', function (): void {
        Process::fake(fn (PendingProcess $process) => (
            $process->command === lifecycleIncus('network', 'list', 'local:', '--format=json')
                ? Process::result(json_encode([[
                    'name' => 'oe-nck-123',
                    'config' => ['user.orbit.e2e.owner' => 'other'],
                ]], JSON_THROW_ON_ERROR))
                : Process::result('', 'Unexpected command.', 2)
        ));

        expect(fn () => lifecycleHost()->setNetworkConfiguration('oe-nck-123', ['ipv4.nat' => 'true']))
            ->toThrow(RuntimeException::class, 'Incus network oe-nck-123 ownership metadata does not match.');
        Process::assertNotRan(lifecycleIncus('network', 'set', 'local:oe-nck-123', 'ipv4.nat', 'true'));
    });

    it('passes multiple network settings as key=value operands', function (): void {
        Process::fake(function (PendingProcess $process) {
            if ($process->command === lifecycleIncus('network', 'list', 'local:', '--format=json')) {
                return Process::result(json_encode([[
                    'name' => 'oe-nck-123',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }
            expect($process->command)->toBe(lifecycleIncus(
                'network',
                'set',
                'local:oe-nck-123',
                'ipv4.nat=true',
                'ipv6.address=none',
            ));

            return Process::result('');
        });

        lifecycleHost()->setNetworkConfiguration('oe-nck-123', [
            'ipv4.nat' => 'true',
            'ipv6.address' => 'none',
        ]);
    });

    it('removes exact rules before deleting the network and tolerates absent rules', function (): void {
        $networkLists = 0;
        $commands = [];

        Process::fake(function (PendingProcess $process) use (&$networkLists, &$commands) {
            $commands[] = $process->command;
            if ($process->command === lifecycleIncus('network', 'list', 'local:', '--format=json')) {
                $networkLists++;

                return Process::result(
                    $networkLists < 3
                        ? json_encode([[
                            'name' => 'oe-nck-123',
                            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                        ]], JSON_THROW_ON_ERROR) : '[]',
                );
            }
            if ($process->command === lifecycleFirewallHelper()) {
                return Process::result("{\"changed\":false}\n");
            }
            if ($process->command === lifecycleIncus('network', 'delete', 'local:oe-nck-123')) {
                return Process::result();
            }

            return Process::result('', 'Unexpected command.', 2);
        });

        new IncusNetworkLifecycle(lifecycleHost())->delete('oe-nck-123');

        expect(array_search(lifecycleFirewallHelper(), $commands, true))
            ->toBeLessThan(
                array_search(lifecycleIncus('network', 'delete', 'local:oe-nck-123'), $commands, true),
            );
    });

    it('refuses foreign ownership before firewall or Incus mutation', function (): void {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;

            return Process::result(json_encode([[
                'name' => 'oe-nck-123',
                'config' => ['user.orbit.e2e.owner' => 'someone-else'],
            ]], JSON_THROW_ON_ERROR));
        });

        expect(fn () => new IncusNetworkLifecycle(lifecycleHost())->delete('oe-nck-123'))
            ->toThrow(RuntimeException::class, 'Incus network oe-nck-123 ownership does not match.');
        Process::assertRan(lifecycleIncus('network', 'list', 'local:', '--format=json'));
        expect($commands)->toHaveCount(1);
    });

    it('refuses a non-local Incus remote before any mutation', function (): void {
        expect(fn () => new IncusNetworkLifecycle(lifecycleHost('lab'))->create('oe-nck-123', 2))
            ->toThrow(RuntimeException::class, 'Host forwarding requires the local Incus remote.');

        Process::assertNothingRan();
    });

    it('refuses a network outside the managed interface prefix before any mutation', function (): void {
        expect(fn () => new IncusNetworkLifecycle(lifecycleHost())->create('unrelated', 2))
            ->toThrow(RuntimeException::class, 'Incus network name is outside the managed interface prefix.');

        Process::assertNothingRan();
    });
});
