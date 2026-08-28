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

/** @param list<string> $arguments
 * @return list<string>
 */
function lifecycleIptables(string ...$arguments): array
{
    return ['sudo', '-n', 'iptables', '-w', '5', ...$arguments];
}

/** @return list<list<string>> */
function lifecycleRules(string $network): array
{
    return [
        ['-i', $network, '-o', $network, '-j', 'ACCEPT'],
        ['-i', $network, '-o', 'oe+', '-j', 'DROP'],
        ['-i', $network, '-m', 'conntrack', '--ctstate', 'NEW,RELATED,ESTABLISHED', '-j', 'ACCEPT'],
        ['-o', $network, '-m', 'conntrack', '--ctstate', 'RELATED,ESTABLISHED', '-j', 'ACCEPT'],
    ];
}

/** @mago-expect lint:cyclomatic-complexity,kan-defect Exact process cases share one lifecycle boundary. */
describe('IncusNetworkLifecycle', function (): void {
    it('creates an IPv4 network and installs exact forwarding rules in order', function (): void {
        $rules = lifecycleRules('oe-nck-123');
        $firstChecks = 0;

        Process::fake(function (PendingProcess $process) use ($rules, &$firstChecks) {
            if (
                $process->command === lifecycleIncus(
                    'network',
                    'create',
                    'local:oe-nck-123',
                    'ipv4.address=auto',
                    'ipv4.nat=true',
                    'ipv6.address=none',
                    'user.orbit.e2e.issue=NCK-123',
                    'user.orbit.e2e.operation=0123456789abcdef0123456789abcdef',
                    'user.orbit.e2e.owner=orbit-e2e',
                )
            ) {
                return Process::result();
            }

            if ($process->command === lifecycleIptables('-C', 'FORWARD', ...$rules[0])) {
                return ++$firstChecks === 1 ? Process::result() : Process::result('', '', 1);
            }

            foreach (array_slice($rules, 1) as $rule) {
                if ($process->command === lifecycleIptables('-C', 'FORWARD', ...$rule)) {
                    return Process::result('', '', 1);
                }
            }

            if ($process->command === lifecycleIptables('-D', 'FORWARD', ...$rules[0])) {
                return Process::result();
            }

            foreach (array_reverse($rules) as $rule) {
                if ($process->command === lifecycleIptables('-I', 'FORWARD', '1', ...$rule)) {
                    return Process::result();
                }
            }

            return Process::result('', 'Unexpected command.', 2);
        });

        $network = new IncusNetworkLifecycle(lifecycleHost())->create('oe-nck-123', [
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
                'ipv4.address=auto',
                'ipv4.nat=true',
                'ipv6.address=none',
                'user.orbit.e2e.issue=NCK-123',
                'user.orbit.e2e.operation=0123456789abcdef0123456789abcdef',
                'user.orbit.e2e.owner=orbit-e2e',
            ),
            lifecycleIptables('-C', 'FORWARD', ...$rules[0]),
            lifecycleIptables('-D', 'FORWARD', ...$rules[0]),
            lifecycleIptables('-C', 'FORWARD', ...$rules[0]),
            lifecycleIptables('-C', 'FORWARD', ...$rules[1]),
            lifecycleIptables('-C', 'FORWARD', ...$rules[2]),
            lifecycleIptables('-C', 'FORWARD', ...$rules[3]),
            lifecycleIptables('-I', 'FORWARD', '1', ...$rules[3]),
            lifecycleIptables('-I', 'FORWARD', '1', ...$rules[2]),
            lifecycleIptables('-I', 'FORWARD', '1', ...$rules[1]),
            lifecycleIptables('-I', 'FORWARD', '1', ...$rules[0]),
        ]);
    });

    it('fails when more than eight duplicate rules exist', function (): void {
        $rule = lifecycleRules('oe-nck-123')[0];
        $checks = 0;
        $deletes = 0;

        Process::fake(function (PendingProcess $process) use ($rule, &$checks, &$deletes) {
            if (
                $process->command === lifecycleIncus(
                    'network',
                    'create',
                    'local:oe-nck-123',
                    'ipv4.address=auto',
                    'ipv4.nat=true',
                    'ipv6.address=none',
                    'user.orbit.e2e.owner=orbit-e2e',
                )
            ) {
                return Process::result();
            }
            if ($process->command === lifecycleIptables('-C', 'FORWARD', ...$rule)) {
                $checks++;

                return Process::result();
            }
            if ($process->command === lifecycleIptables('-D', 'FORWARD', ...$rule)) {
                $deletes++;

                return Process::result();
            }

            return Process::result('', 'Unexpected command.', 2);
        });

        expect(fn () => new IncusNetworkLifecycle(lifecycleHost())->create('oe-nck-123'))
            ->toThrow(RuntimeException::class, 'More than eight duplicate forwarding rules exist.');
        expect($checks)->toBe(9);
        expect($deletes)->toBe(8);
    });

    it('reconciles forwarding for an existing owned network', function (): void {
        $rules = lifecycleRules('oe-nck-123');

        Process::fake(function (PendingProcess $process) use ($rules) {
            if ($process->command === lifecycleIncus('network', 'list', 'local:', '--format=json')) {
                return Process::result(json_encode([[
                    'name' => 'oe-nck-123',
                    'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                ]], JSON_THROW_ON_ERROR));
            }

            foreach ($rules as $rule) {
                if ($process->command === lifecycleIptables('-C', 'FORWARD', ...$rule)) {
                    return Process::result('', '', 1);
                }
                if ($process->command === lifecycleIptables('-I', 'FORWARD', '1', ...$rule)) {
                    return Process::result();
                }
            }

            return Process::result('', 'Unexpected command.', 2);
        });

        new IncusNetworkLifecycle(lifecycleHost())->reconcile('oe-nck-123');

        Process::assertNotRan(lifecycleIncus(
            'network',
            'create',
            'local:oe-nck-123',
            'ipv4.address=auto',
            'ipv4.nat=true',
            'ipv6.address=none',
            'user.orbit.e2e.owner=orbit-e2e',
        ));
        foreach ($rules as $rule) {
            Process::assertRan(lifecycleIptables('-I', 'FORWARD', '1', ...$rule));
        }
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
            foreach (lifecycleRules('oe-nck-123') as $rule) {
                if ($process->command === lifecycleIptables('-C', 'FORWARD', ...$rule)) {
                    return Process::result('', '', 1);
                }
            }
            if ($process->command === lifecycleIncus('network', 'delete', 'local:oe-nck-123')) {
                return Process::result();
            }

            return Process::result('', 'Unexpected command.', 2);
        });

        new IncusNetworkLifecycle(lifecycleHost())->delete('oe-nck-123');

        expect(array_search(
            lifecycleIptables(
                '-C',
                'FORWARD',
                ...lifecycleRules('oe-nck-123')[0],
            ),
            $commands,
            true,
        ))
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
        expect(fn () => new IncusNetworkLifecycle(lifecycleHost('lab'))->create('oe-nck-123'))
            ->toThrow(RuntimeException::class, 'Host forwarding requires the local Incus remote.');

        Process::assertNothingRan();
    });

    it('refuses a network outside the managed interface prefix before any mutation', function (): void {
        expect(fn () => new IncusNetworkLifecycle(lifecycleHost())->create('unrelated'))
            ->toThrow(RuntimeException::class, 'Incus network name is outside the managed interface prefix.');

        Process::assertNothingRan();
    });
});
