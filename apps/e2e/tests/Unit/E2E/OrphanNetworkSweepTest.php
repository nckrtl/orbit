<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\OrphanNetworkSweep;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyTarget;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    Process::preventStrayProcesses();
});

function sweepNetwork(string $name, array $usedBy = []): IncusNetwork
{
    return new IncusNetwork('local', 'default', $name, [], [], $usedBy);
}

/**
 * @param list<array{name:string,used_by?:list<string>}> $networks
 * @param list<array<int, string>> $commands
 */
function fakeSweepIncus(array &$networks, array &$commands): void
{
    Process::fake(function (\Illuminate\Process\PendingProcess $process) use (&$networks, &$commands) {
        $command = $process->command;
        $commands[] = $command;
        if (($command[0] ?? null) === 'python3') {
            return Process::result('{"changed":true}');
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
            return Process::result(json_encode(array_values($networks), JSON_THROW_ON_ERROR));
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'delete') {
            $name = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));
            $networks = array_values(array_filter(
                $networks,
                static fn (array $network): bool => $network['name'] !== $name,
            ));

            return Process::result();
        }

        return Process::result();
    });
}

function sweep(StatePaths $paths): OrphanNetworkSweep
{
    $host = new IncusHost;

    return new OrphanNetworkSweep(
        $host,
        new IncusNetworkLifecycle($host),
        $paths,
        new OperationId(str_repeat('a', 32)),
    );
}

describe('orphan network filter', function () {
    it('selects unused harness networks of both prefixes and nothing else', function () {
        $networks = [
            'oe-orphan' => sweepNetwork('oe-orphan'),
            'orbit-e2e-n-legacy' => sweepNetwork('orbit-e2e-n-legacy'),
            'orbit-e2e-p-n-legacy' => sweepNetwork('orbit-e2e-p-n-legacy'),
            'oe-used' => sweepNetwork('oe-used', ['/1.0/instances/orbit-e2e-nck-12-aaaaaaaa-gateway']),
            'oe-standby' => sweepNetwork('oe-standby'),
            'incusbr0' => sweepNetwork('incusbr0'),
            'control-unused' => sweepNetwork('control-unused'),
            'docker0' => sweepNetwork('docker0'),
            'oe' => sweepNetwork('oe'),
            'xoe-orphan' => sweepNetwork('xoe-orphan'),
        ];

        expect(OrphanNetworkSweep::orphans($networks))
            ->toBe(['oe-orphan', 'orbit-e2e-n-legacy', 'orbit-e2e-p-n-legacy']);
    });

    it('never selects the standby network even when it has no users', function () {
        expect(OrphanNetworkSweep::orphans(['oe-standby' => sweepNetwork('oe-standby')]))->toBe([]);
    });

    it('never selects an explicitly protected network', function () {
        $protected = TopologyTarget::feature('NCK-12', new AttemptId(str_repeat('a', 32)))->network();

        expect(OrphanNetworkSweep::orphans([
            $protected => sweepNetwork($protected),
            'oe-orphan' => sweepNetwork('oe-orphan'),
        ], [$protected]))->toBe(['oe-orphan']);
    });

    it('recognizes harness network names by exact prefix', function () {
        expect(OrphanNetworkSweep::isHarnessNetworkName('oe-abc'))
            ->toBeTrue()
            ->and(OrphanNetworkSweep::isHarnessNetworkName('orbit-e2e-n-1'))
            ->toBeTrue()
            ->and(OrphanNetworkSweep::isHarnessNetworkName('oe'))
            ->toBeFalse()
            ->and(OrphanNetworkSweep::isHarnessNetworkName('incusbr0'))
            ->toBeFalse()
            ->and(OrphanNetworkSweep::isHarnessNetworkName('orbit-e2e'))
            ->toBeFalse();
    });
});

describe('orphan network sweep', function () {
    it('deletes only orphans and removes their firewall rules', function () {
        $paths = new StatePaths(temporaryPath('orbit-sweep-', 8));
        $networks = [
            ['name' => 'oe-standby', 'used_by' => []],
            ['name' => 'oe-orphan', 'used_by' => []],
            ['name' => 'orbit-e2e-n-legacy', 'used_by' => []],
            ['name' => 'oe-used', 'used_by' => ['/1.0/instances/x']],
            ['name' => 'control-unused', 'used_by' => []],
            ['name' => 'incusbr0', 'used_by' => ['/1.0/profiles/default']],
        ];
        $commands = [];
        fakeSweepIncus($networks, $commands);

        $reaped = sweep($paths)->sweep();

        $deletes = array_values(array_filter(
            $commands,
            static fn (array $command): bool => (
                ($command[3] ?? null) === 'network'
                && ($command[4] ?? null) === 'delete'
            ),
        ));
        $firewall = array_values(array_filter(
            $commands,
            static fn (array $command): bool => ($command[0] ?? null) === 'python3',
        ));
        expect($reaped)
            ->toBe(['oe-orphan', 'orbit-e2e-n-legacy'])
            ->and(array_map(static fn (array $command): string => $command[5], $deletes))
            ->toBe(['local:oe-orphan', 'local:orbit-e2e-n-legacy'])
            ->and($firewall)
            ->toHaveCount(1)
            ->and(array_column($networks, 'name'))
            ->toBe(['oe-standby', 'oe-used', 'control-unused', 'incusbr0']);
    });

    it('waits for the topology creation lock before sweeping', function () {
        $paths = new StatePaths(temporaryPath('orbit-sweep-', 8));
        $holder = new \App\E2E\State\OperationLock($paths);
        $holder->acquire(OrphanNetworkSweep::CREATION_LOCK, new OperationId(str_repeat('b', 32)));
        $commands = [];
        $networks = [['name' => 'oe-orphan', 'used_by' => []]];
        fakeSweepIncus($networks, $commands);
        $sweep = new OrphanNetworkSweep(
            $host = new IncusHost,
            new IncusNetworkLifecycle($host),
            $paths,
            new OperationId(str_repeat('a', 32)),
        );
        $holder->release();

        expect($sweep->sweep())->toBe(['oe-orphan']);
    });

    it('reports nothing and deletes nothing when no orphan exists', function () {
        $paths = new StatePaths(temporaryPath('orbit-sweep-', 8));
        $networks = [['name' => 'oe-standby', 'used_by' => []], ['name' => 'incusbr0', 'used_by' => []]];
        $commands = [];
        fakeSweepIncus($networks, $commands);

        expect(sweep($paths)->sweep())
            ->toBe([])
            ->and(array_filter($commands, static fn (array $command): bool => ($command[4] ?? null) === 'delete'))
            ->toBe([]);
    });

    it('refuses to delete a network outside the harness prefixes or one with users', function () {
        $networks = [
            ['name' => 'control-unused', 'used_by' => []],
            ['name' => 'oe-used', 'used_by' => ['/1.0/instances/x']],
        ];
        $commands = [];
        fakeSweepIncus($networks, $commands);
        $host = new IncusHost;

        expect(fn () => $host->deleteOrphanNetwork('control-unused'))
            ->toThrow(RuntimeException::class, 'outside the harness prefixes')
            ->and(fn () => $host->deleteOrphanNetwork('oe-used'))
            ->toThrow(RuntimeException::class, 'still in use')
            ->and(fn () => new IncusNetworkLifecycle($host)->deleteOrphan('incusbr0'))
            ->toThrow(RuntimeException::class, 'outside the harness prefixes')
            ->and(array_filter($commands, static fn (array $command): bool => ($command[4] ?? null) === 'delete'))
            ->toBe([]);
    });

    it('fails when a reaped network is still listed after deletion', function () {
        $paths = new StatePaths(temporaryPath('orbit-sweep-', 8));
        Process::fake(function (\Illuminate\Process\PendingProcess $process) {
            if (($process->command[0] ?? null) === 'python3') {
                return Process::result('{"changed":true}');
            }
            if (($process->command[4] ?? null) === 'list') {
                return Process::result('[{"name":"oe-orphan","used_by":[]}]');
            }

            return Process::result();
        });

        expect(fn () => sweep($paths)->sweep())->toThrow(RuntimeException::class, 'remain after the sweep');
    });
});
