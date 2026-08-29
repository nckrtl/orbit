<?php

declare(strict_types=1);

use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\OperationId;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

/** @return array{HostCapacity, AtomicJsonStore} */
function hostCapacity(int $maxVms = 9, string $commandOperation = 'a', ?IncusHost $host = null): array
{
    $paths = new StatePaths(sys_get_temp_dir().'/orbit-capacity-'.bin2hex(random_bytes(5)));
    $store = new AtomicJsonStore($paths);

    return [
        new HostCapacity($store, $paths, new OperationId(str_repeat($commandOperation, 32)), $maxVms, $host),
        $store,
    ];
}

describe('host capacity', function (): void {
    it('counts the three standby VMs and reserves three slots per feature topology', function (): void {
        [$capacity, $store] = hostCapacity();

        $firstSlot = $capacity->reserve('NCK-101', new OperationId(str_repeat('1', 32)));
        $secondSlot = $capacity->reserve('NCK-102', new OperationId(str_repeat('2', 32)));

        expect(fn () => $capacity->reserve('NCK-103', new OperationId(str_repeat('3', 32))))
            ->toThrow(RuntimeException::class, 'Incus host capacity is exhausted.');
        expect([$firstSlot, $secondSlot])
            ->toBe([2, 3])
            ->and($store->read('capacity/incus.json'))
            ->toBe([
                'schema' => 1,
                'reservations' => [
                    'NCK-101' => ['operation_id' => str_repeat('1', 32), 'slots' => 3, 'network_slot' => 2],
                    'NCK-102' => ['operation_id' => str_repeat('2', 32), 'slots' => 3, 'network_slot' => 3],
                ],
            ]);
    });

    it('reuses only the exact retained reservation for interrupted acquisition', function (): void {
        [$capacity] = hostCapacity();
        $operation = new OperationId(str_repeat('4', 32));

        $first = $capacity->reserve('NCK-201', $operation);
        $retained = $capacity->reserve('NCK-201', $operation);

        expect([$first, $retained])
            ->toBe([2, 2])
            ->and(fn () => $capacity->reserve('NCK-201', new OperationId(str_repeat('5', 32))))
            ->toThrow(RuntimeException::class, 'another acquisition operation');
    });

    it('releases only an exact issue and acquisition owner', function (): void {
        [$capacity, $store] = hostCapacity(maxVms: 6);
        $operation = new OperationId(str_repeat('6', 32));
        $capacity->reserve('NCK-301', $operation);

        expect(fn () => $capacity->release('NCK-301', new OperationId(str_repeat('7', 32))))
            ->toThrow(RuntimeException::class, 'capacity reservation ownership does not match');

        $capacity->release('NCK-301', $operation);
        $capacity->release('NCK-301', $operation);

        expect($store->read('capacity/incus.json'))->toBe([
            'schema' => 1,
            'reservations' => [],
        ]);
    });

    it('never reclaims a retained reservation from age alone', function (): void {
        [$capacity, $store] = hostCapacity(maxVms: 6);
        $operation = new OperationId(str_repeat('8', 32));
        $capacity->reserve('NCK-401', $operation);
        $ledger = $store->read('capacity/incus.json');

        expect($ledger['reservations']['NCK-401'])
            ->toBe(['operation_id' => str_repeat('8', 32), 'slots' => 3, 'network_slot' => 2])
            ->not->toHaveKeys(['created_at', 'expires_at', 'pid']);
        expect(fn () => $capacity->reserve('NCK-402', new OperationId(str_repeat('9', 32))))
            ->toThrow(RuntimeException::class, 'Incus host capacity is exhausted.');
    });

    it('rejects duplicate retained network slots', function (): void {
        [$capacity, $store] = hostCapacity();
        $store->write('capacity/incus.json', [
            'schema' => 1,
            'reservations' => [
                'NCK-501' => ['operation_id' => str_repeat('1', 32), 'slots' => 3, 'network_slot' => 2],
                'NCK-502' => ['operation_id' => str_repeat('2', 32), 'slots' => 3, 'network_slot' => 2],
            ],
        ]);

        expect(fn () => $capacity->reserve('NCK-503', new OperationId(str_repeat('3', 32))))
            ->toThrow(RuntimeException::class, 'capacity ledger is invalid');
    });

    it('skips deterministic slots occupied by external Incus networks', function (): void {
        $inventoryReads = 0;
        Process::fake(function (\Illuminate\Process\PendingProcess $process) use (&$inventoryReads) {
            expect($process->command)->toBe([
                'incus',
                '--project',
                'orbit',
                'network',
                'list',
                'lab:',
                '--format=json',
            ]);
            $inventoryReads++;

            return Process::result(json_encode([
                ['name' => 'legacy-net', 'config' => ['ipv4.address' => '10.232.2.1/24']],
            ], JSON_THROW_ON_ERROR));
        });
        [$capacity] = hostCapacity(host: new IncusHost(remote: 'lab', project: 'orbit'));

        expect($capacity->reserve('NCK-601', new OperationId(str_repeat('a', 32))))
            ->toBe(3)
            ->and($inventoryReads)
            ->toBe(1);
    });

    it('treats canonical network address entries as occupied', function (): void {
        Process::fake(function (\Illuminate\Process\PendingProcess $process) {
            return Process::result(json_encode([
                ['name' => 'legacy-net', 'config' => ['ipv4.address' => '10.232.2.0/24']],
            ], JSON_THROW_ON_ERROR));
        });
        [$capacity] = hostCapacity(host: new IncusHost(remote: 'lab', project: 'orbit'));

        expect($capacity->reserve('NCK-602', new OperationId(str_repeat('b', 32))))->toBe(3);
    });

    it('rejects malformed relevant deterministic network addresses', function (): void {
        Process::fake(function (\Illuminate\Process\PendingProcess $process) {
            return Process::result(json_encode([
                ['name' => 'broken-net', 'config' => ['ipv4.address' => '10.232.2.1/16']],
            ], JSON_THROW_ON_ERROR));
        });
        [$capacity] = hostCapacity(host: new IncusHost(remote: 'lab', project: 'orbit'));

        expect(fn () => $capacity->reserve('NCK-603', new OperationId(str_repeat('c', 32))))
            ->toThrow(RuntimeException::class, 'malformed deterministic IPv4 address');
    });
});
