<?php

declare(strict_types=1);

use App\E2E\HostCapacity;
use App\E2E\IncusHost;
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

        expect(new HostCapacity(new IncusHost, 6)->reserveSlot())->toBe(3);
    });

    it('refuses one more topology when the VM budget is reached', function () {
        fakeCapacityHost([
            'orbit-e2e-standby-gateway',
            'orbit-e2e-standby-app-dev',
            'orbit-e2e-standby-app-prod',
            'orbit-e2e-nck-1-aaaaaaaa-gateway',
        ], ['10.232.1.1/24']);

        expect(fn () => new HostCapacity(new IncusHost, 6)->reserveSlot())
            ->toThrow(RuntimeException::class, 'capacity is exhausted: 4 harness VMs exist and the limit is 6')
            ->and(fn () => new HostCapacity(new IncusHost, 5))
            ->toThrow(RuntimeException::class, 'cannot fit');
    });
});
