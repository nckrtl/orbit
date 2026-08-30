<?php

declare(strict_types=1);

use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\Value\StandbyIdentity;
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
        fakeCapacityHost(['orbit-e2e-standby-gateway', 'orbit-e2e-standby-app-dev', 'orbit-e2e-standby-app-prod'], [
            '10.232.1.1/24',
            '10.232.2.1/24',
            '10.232.4.1/24',
            '192.168.1.1/24',
        ]);

        expect(new HostCapacity(new IncusHost, 9)->reserveSlot())->toBe(3);
    });

    it('never hands out the network slot of a standby another checkout owns', function () {
        fakeCapacityHost([], []);

        $slots = [];
        foreach (StandbyIdentity::known() as $standby) {
            $slots[] = $standby->slot;
        }

        expect($slots)
            ->toContain(1, 200)
            ->and(new HostCapacity(new IncusHost, 9)->reserveSlot())
            ->toBe(2)
            ->and(array_map(
                static fn (int $slot): string => '10.232.'.$slot.'.1/24',
                range(2, 199),
            ))
            ->toHaveCount(198);

        // Every slot but the standby slots is free: the scan stops before 200.
        fakeCapacityHost([], array_map(
            static fn (int $slot): string => '10.232.'.$slot.'.1/24',
            range(2, 199),
        ));

        expect(fn () => new HostCapacity(new IncusHost, 9)->reserveSlot())
            ->toThrow(RuntimeException::class, 'network slots are exhausted');
    });

    it('counts the VMs of every standby against the budget', function () {
        fakeCapacityHost([
            ...StandbyIdentity::primary()->instances(),
            ...StandbyIdentity::live()->instances(),
            'orbit-e2e-nck-1-aaaaaaaa-gateway',
        ], ['10.232.1.1/24', '10.232.200.1/24']);

        expect(fn () => new HostCapacity(new IncusHost, 9)->reserveSlot())
            ->toThrow(RuntimeException::class, 'capacity is exhausted: 7 harness VMs exist and the limit is 9')
            ->and(new HostCapacity(new IncusHost, 12)->reserveSlot())
            ->toBe(2);
    });

    it('refuses one more topology when the VM budget is reached', function () {
        fakeCapacityHost([
            'orbit-e2e-standby-gateway',
            'orbit-e2e-standby-app-dev',
            'orbit-e2e-standby-app-prod',
            'orbit-e2e-nck-1-aaaaaaaa-gateway',
            'orbit-e2e-nck-1-aaaaaaaa-app-dev',
            'orbit-e2e-nck-1-aaaaaaaa-app-prod',
            'orbit-e2e-nck-2-bbbbbbbb-gateway',
            'orbit-e2e-nck-2-bbbbbbbb-app-dev',
            'orbit-e2e-nck-2-bbbbbbbb-app-prod',
        ], ['10.232.1.1/24']);

        expect(fn () => new HostCapacity(new IncusHost, 9)->reserveSlot())
            ->toThrow(RuntimeException::class, 'capacity is exhausted: 9 harness VMs exist and the limit is 9')
            ->and(fn () => new HostCapacity(new IncusHost, 9)->reserveSlot())
            ->toThrow(RuntimeException::class, 'Raise ORBIT_E2E_INCUS_MAX_VMS, or release a topology.')
            ->and(fn () => new HostCapacity(new IncusHost, 8))
            ->toThrow(RuntimeException::class, 'cannot fit');
    });

    it('budgets seven feature topologies beside the standby by default', function () {
        $config = require dirname(__DIR__, 3).'/config/e2e.php';

        expect($config['incus']['max_vms'])->toBe(24);
    });
});
