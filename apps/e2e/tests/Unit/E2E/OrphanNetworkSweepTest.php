<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\OrphanNetworkSweep;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationJournal;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\OperationId;
use App\E2E\Value\ReleaseResult;
use App\E2E\Value\TopologyTarget;
use Illuminate\Container\Container;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
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
 * Model `resources/host/reconcile-firewall.py`: it accepts only managed `oe-*`
 * names and exits 2 with `invalid network` for anything else.
 */
function fakeFirewallHelper(PendingProcess $process): ProcessResult
{
    $input = json_decode((string) $process->input, true, 8, JSON_THROW_ON_ERROR);
    $network = is_array($input) ? $input['network'] ?? '' : '';
    if (! is_string($network) || preg_match('/\Aoe-[a-z0-9](?:[a-z0-9-]{0,10}[a-z0-9])?\z/D', $network) !== 1) {
        return Process::result('', 'invalid network', 2);
    }

    return Process::result('{"changed":true}');
}

/**
 * @param list<array{name:string,used_by?:list<string>}> $networks
 * @param list<array<int, string>> $commands
 * @param list<string> $failing Networks whose `incus network delete` fails.
 */
function fakeSweepIncus(array &$networks, array &$commands, array $failing = []): void
{
    Process::fake(function (PendingProcess $process) use (&$networks, &$commands, $failing) {
        $command = $process->command;
        $commands[] = $command;
        if (($command[0] ?? null) === 'python3') {
            return fakeFirewallHelper($process);
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'list') {
            return Process::result(json_encode(array_values($networks), JSON_THROW_ON_ERROR));
        }
        if (($command[3] ?? null) === 'network' && ($command[4] ?? null) === 'delete') {
            $name = preg_replace('/\A[^:]+:/', '', (string) ($command[5] ?? ''));
            if (in_array($name, $failing, true)) {
                return Process::result('', 'Error: network in use by another process', 1);
            }
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
        new AtomicJsonStore($paths),
        $paths,
        new OperationJournal($paths, new SecretRedactor),
        new OperationId(str_repeat('a', 32)),
    );
}

/** @return list<array{0:mixed,1:mixed}> */
function sweepJournal(StatePaths $paths): array
{
    return array_map(
        static fn (array $entry): array => [$entry['state'] ?? null, $entry['network'] ?? null],
        new OperationJournal($paths, new SecretRedactor)->entries(new OperationId(str_repeat('a', 32))),
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

    it('never selects a protected network of an active lease', function () {
        $leased = TopologyTarget::feature('NCK-12', new AttemptId(str_repeat('a', 32)))->network();

        expect(OrphanNetworkSweep::orphans([
            $leased => sweepNetwork($leased),
            'oe-orphan' => sweepNetwork('oe-orphan'),
        ], [$leased]))->toBe(['oe-orphan']);
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
    it('deletes only orphans, reconciles oe-* firewall rules, and journals each deletion', function () {
        $paths = new StatePaths(temporaryPath('orbit-sweep-', 8));
        $leased = TopologyTarget::feature('NCK-12', new AttemptId(str_repeat('a', 32)))->network();
        new AtomicJsonStore($paths)->write('leases/NCK-12.json', [
            'issue' => 'NCK-12',
            'attempt' => str_repeat('a', 32),
            'state' => 'acquiring',
        ]);
        $networks = [
            ['name' => 'oe-standby', 'used_by' => []],
            ['name' => 'oe-orphan', 'used_by' => []],
            ['name' => 'orbit-e2e-n-legacy', 'used_by' => []],
            ['name' => 'oe-used', 'used_by' => ['/1.0/instances/x']],
            ['name' => $leased, 'used_by' => []],
            ['name' => 'control-unused', 'used_by' => []],
            ['name' => 'incusbr0', 'used_by' => ['/1.0/profiles/default']],
        ];
        $commands = [];
        fakeSweepIncus($networks, $commands);
        $deleted = [];

        $result = sweep($paths)->sweep(function (string $name) use (&$deleted): void {
            $deleted[] = $name;
        });

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
        expect($result->reaped)
            ->toBe(['oe-orphan', 'orbit-e2e-n-legacy'])
            ->and($result->failed)
            ->toBe([])
            ->and($deleted)
            ->toBe(['oe-orphan', 'orbit-e2e-n-legacy'])
            ->and(array_map(static fn (array $command): string => $command[5], $deletes))
            ->toBe(['local:oe-orphan', 'local:orbit-e2e-n-legacy'])
            ->and(count($firewall))
            ->toBe(1, 'the firewall helper refuses legacy names, so only the oe-* orphan is reconciled')
            ->and(array_column($networks, 'name'))
            ->toBe(['oe-standby', 'oe-used', $leased, 'control-unused', 'incusbr0'])
            ->and(sweepJournal($paths))
            ->toBe([
                ['deleted', 'oe-orphan'],
                ['deleted', 'orbit-e2e-n-legacy'],
            ]);
    });

    it('deletes a legacy orbit-e2e-* orphan without any firewall reconcile', function () {
        $paths = new StatePaths(temporaryPath('orbit-sweep-', 8));
        $networks = [['name' => 'orbit-e2e-p-n-legacy', 'used_by' => []], ['name' => 'oe-standby', 'used_by' => []]];
        $commands = [];
        fakeSweepIncus($networks, $commands);

        $result = sweep($paths)->sweep();

        expect($result->reaped)
            ->toBe(['orbit-e2e-p-n-legacy'])
            ->and($result->failed)
            ->toBe([])
            ->and(array_column($networks, 'name'))
            ->toBe(['oe-standby'])
            ->and(array_filter($commands, static fn (array $command): bool => ($command[0] ?? null) === 'python3'))
            ->toBe([]);
    });

    it('records every successful deletion and continues past a failing network', function () {
        $paths = new StatePaths(temporaryPath('orbit-sweep-', 8));
        $networks = [
            ['name' => 'oe-orphan-a', 'used_by' => []],
            ['name' => 'oe-orphan-b', 'used_by' => []],
            ['name' => 'orbit-e2e-n-legacy', 'used_by' => []],
        ];
        $commands = [];
        fakeSweepIncus($networks, $commands, failing: ['oe-orphan-b']);
        $deleted = [];

        $result = sweep($paths)->sweep(function (string $name) use (&$deleted): void {
            $deleted[] = $name;
        });

        expect($result->reaped)
            ->toBe(['oe-orphan-a', 'orbit-e2e-n-legacy'])
            ->and(array_keys($result->failed))
            ->toBe(['oe-orphan-b'])
            ->and($result->failures()[0])
            ->toStartWith('oe-orphan-b: ')
            ->and($deleted)
            ->toBe(['oe-orphan-a', 'orbit-e2e-n-legacy'])
            ->and(array_column($networks, 'name'))
            ->toBe(['oe-orphan-b'])
            ->and(sweepJournal($paths))
            ->toBe([
                ['deleted', 'oe-orphan-a'],
                ['failed',  'oe-orphan-b'],
                ['deleted', 'orbit-e2e-n-legacy'],
            ]);
    });

    it('reports nothing and deletes nothing when no orphan exists', function () {
        $paths = new StatePaths(temporaryPath('orbit-sweep-', 8));
        $networks = [['name' => 'oe-standby', 'used_by' => []], ['name' => 'incusbr0', 'used_by' => []]];
        $commands = [];
        fakeSweepIncus($networks, $commands);

        $result = sweep($paths)->sweep();

        expect($result->reaped)
            ->toBe([])
            ->and($result->failed)
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

    it('reports a reaped network that is still listed after deletion as failed', function () {
        $paths = new StatePaths(temporaryPath('orbit-sweep-', 8));
        Process::fake(function (PendingProcess $process) {
            if (($process->command[0] ?? null) === 'python3') {
                return fakeFirewallHelper($process);
            }
            if (($process->command[4] ?? null) === 'list') {
                return Process::result('[{"name":"oe-orphan","used_by":[]}]');
            }

            return Process::result();
        });

        $result = sweep($paths)->sweep();

        expect($result->reaped)
            ->toBe([])
            ->and($result->failures())
            ->toBe(['oe-orphan: The network is still listed after deletion.']);
    });
});

describe('release evidence with reaped networks', function () {
    it('round-trips the sweep keys and reads receipts written before them', function () {
        $result = new ReleaseResult(
            str_repeat('a', 32),
            str_repeat('b', 32),
            'NCK-12',
            new AttemptId(str_repeat('c', 32)),
            AttemptPurpose::Discovery,
            [],
            [],
            ['oe-abc'],
            '2026-08-30T10:00:00Z',
            ['oe-orphan'],
            ['oe-stuck: still in use'],
        );
        $legacy = $result->toArray();
        unset($legacy['networks_reaped'], $legacy['networks_failed']);
        $reapedOnly = $result->toArray();
        unset($reapedOnly['networks_failed']);
        $misordered = [...$legacy, 'networks_reaped' => []];

        expect(array_keys($result->toArray()))
            ->toBe(ReleaseResult::KEYS)
            ->and(ReleaseResult::fromArray($result->toArray())->toArray())
            ->toBe($result->toArray())
            ->and(ReleaseResult::fromArray($legacy)->networksReaped)
            ->toBe([])
            ->and(ReleaseResult::fromArray($legacy)->networksFailed)
            ->toBe([])
            ->and(ReleaseResult::fromArray($legacy)->toArray()['released_at'])
            ->toBe('2026-08-30T10:00:00Z')
            ->and(ReleaseResult::fromArray($reapedOnly)->networksReaped)
            ->toBe(['oe-orphan'])
            ->and(fn () => ReleaseResult::fromArray($misordered))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => ReleaseResult::fromArray([...$result->toArray(), 'networks_reaped' => [1]]))
            ->toThrow(InvalidArgumentException::class);
    });
});
