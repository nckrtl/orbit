<?php

declare(strict_types=1);

use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\Value\TopologySnapshotIdentity;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

/**
 * @param list<string> $harnessInstances
 * @param list<string> $subnets
 */
function fakeCapacityHost(array $harnessInstances, array $subnets): void
{
    Process::fake(function (PendingProcess $process) use ($harnessInstances, $subnets) {
        $command = $process->command;
        assert(is_array($command));
        if (($command[3] ?? null) === 'network') {
            return Process::result(json_encode(array_map(
                static fn (string $subnet): array => [
                    'name' => 'oe-'.md5($subnet),
                    'config' => ['ipv4.address' => $subnet],
                ],
                $subnets,
            ), JSON_THROW_ON_ERROR));
        }
        $instances = array_map(
            static fn (string $name): array => [
                'name' => $name,
                'type' => 'virtual-machine',
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            ],
            $harnessInstances,
        );
        $instances[] = ['name' => 'unrelated-vm', 'type' => 'virtual-machine', 'config' => []];

        return Process::result(json_encode($instances, JSON_THROW_ON_ERROR));
    });
}

describe('HostCapacity', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('counts harness VMs from the Incus inventory and hands out the lowest free network slot', function () {
        fakeCapacityHost([
            'orbit-e2e-topology-snapshot-gateway',
            'orbit-e2e-topology-snapshot-app-dev',
            'orbit-e2e-topology-snapshot-app-prod',
        ], [
            '10.232.1.1/24',
            '10.232.2.1/24',
            '10.232.4.1/24',
            '192.168.1.1/24',
        ]);

        expect(new HostCapacity(new IncusHost, 9)->reserveSlot())->toBe(3);
    });

    it('never hands out the persistent topology snapshot network slot', function () {
        fakeCapacityHost([], []);

        expect(TopologySnapshotIdentity::primary()->slot)
            ->toBe(1)
            ->and(new HostCapacity(new IncusHost, 9)->reserveSlot())
            ->toBe(2)
            ->and(array_map(
                static fn (int $slot): string => '10.232.'.$slot.'.1/24',
                range(2, 200),
            ))
            ->toHaveCount(199);

        fakeCapacityHost([], array_map(
            static fn (int $slot): string => '10.232.'.$slot.'.1/24',
            range(2, 200),
        ));

        expect(fn () => new HostCapacity(new IncusHost, 9)->reserveSlot())
            ->toThrow(RuntimeException::class, 'network slots are exhausted');
    });

    it('admits proof beside the persistent snapshot and discovery at the minimum budget', function () {
        fakeCapacityHost([
            ...TopologySnapshotIdentity::primary()->instances(),
            'orbit-e2e-tst-1-aaaaaaaa-gateway',
            'orbit-e2e-tst-1-aaaaaaaa-app-dev',
            'orbit-e2e-tst-1-aaaaaaaa-app-prod',
        ], ['10.232.1.1/24', '10.232.2.1/24']);

        expect(new HostCapacity(new IncusHost, 9)->reserveSlot())->toBe(3);
    });

    it('refuses one more topology when the VM budget is reached', function () {
        fakeCapacityHost([
            'orbit-e2e-topology-snapshot-gateway',
            'orbit-e2e-topology-snapshot-app-dev',
            'orbit-e2e-topology-snapshot-app-prod',
            'orbit-e2e-tst-1-aaaaaaaa-gateway',
            'orbit-e2e-tst-1-aaaaaaaa-app-dev',
            'orbit-e2e-tst-1-aaaaaaaa-app-prod',
            'orbit-e2e-tst-2-bbbbbbbb-gateway',
            'orbit-e2e-tst-2-bbbbbbbb-app-dev',
            'orbit-e2e-tst-2-bbbbbbbb-app-prod',
        ], ['10.232.1.1/24']);

        expect(fn () => new HostCapacity(new IncusHost, 9)->reserveSlot())
            ->toThrow(RuntimeException::class, 'capacity is exhausted: 9 harness VMs exist and the limit is 9')
            ->and(fn () => new HostCapacity(new IncusHost, 9)->reserveSlot())
            ->toThrow(RuntimeException::class, 'Raise ORBIT_E2E_INCUS_MAX_VMS, or release a topology.')
            ->and(fn () => new HostCapacity(new IncusHost, 8))
            ->toThrow(RuntimeException::class, 'cannot fit');
    });

    it('ships a default budget of seven feature topologies beside the persistent snapshot', function () {
        // Read the shipped literal rather than the resolved value: an operator who
        // exported ORBIT_E2E_INCUS_MAX_VMS for one run must still be able to run the suite.
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/config/e2e.php');

        expect($source)->toMatch("/'max_vms'\s*=>\s*\(int\)\s*env\(\s*'ORBIT_E2E_INCUS_MAX_VMS'\s*,\s*24\s*\)/");
    });

    it('admits one more topology at the shipped budget when discovery and proof are present', function () {
        fakeCapacityHost([
            ...array_map(static fn (int $i): string => "orbit-e2e-topology-snapshot-role{$i}", range(1, 3)),
            ...array_map(static fn (int $i): string => "orbit-e2e-tst-1-aaaaaaaa-role{$i}", range(1, 3)),
            ...array_map(static fn (int $i): string => "orbit-e2e-tst-2-bbbbbbbb-role{$i}", range(1, 3)),
        ], ['10.232.1.1/24']);

        expect(new HostCapacity(new IncusHost, 24)->reserveSlot())->toBeGreaterThan(1);
    });

    it('admits and refuses using the requested recipe VM count', function () {
        fakeCapacityHost(array_map(static fn (int $i): string => "orbit-e2e-existing-{$i}", range(1, 5)), []);

        expect(new HostCapacity(new IncusHost, 9)->reserveSlot(4))->toBe(2);
        expect(fn () => new HostCapacity(new IncusHost, 9)->reserveSlot(5))
            ->toThrow(RuntimeException::class, 'capacity is exhausted');
        expect(fn () => new HostCapacity(new IncusHost, 9)->reserveSlot(0))
            ->toThrow(RuntimeException::class, 'outside host capacity');
    });
});
