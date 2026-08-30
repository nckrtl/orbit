<?php

declare(strict_types=1);

use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
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

function attemptFixture(string $character): AttemptId
{
    return new AttemptId(str_repeat($character, 32));
}

/** @return array{HostCapacity, AtomicJsonStore} */
function hostCapacity(int $maxVms = 9, string $commandOperation = 'a', ?IncusHost $host = null): array
{
    $paths = new StatePaths(temporaryPath('orbit-capacity-', 5));
    $store = new AtomicJsonStore($paths);

    return [
        new HostCapacity($store, $paths, new OperationId(str_repeat($commandOperation, 32)), $maxVms, $host),
        $store,
    ];
}

describe('host capacity', function (): void {
    it('counts the three standby VMs and reserves three slots per feature topology', function (): void {
        [$capacity, $store] = hostCapacity();

        $firstSlot = $capacity->reserve('NCK-101', attemptFixture('1'), new OperationId(str_repeat('1', 32)));
        $secondSlot = $capacity->reserve('NCK-102', attemptFixture('2'), new OperationId(str_repeat('2', 32)));

        expect(fn () => $capacity->reserve('NCK-103', attemptFixture('3'), new OperationId(str_repeat('3', 32))))
            ->toThrow(RuntimeException::class, 'Incus host capacity is exhausted.');
        expect([$firstSlot, $secondSlot])
            ->toBe([2, 3])
            ->and($store->read('capacity/incus.json'))
            ->toBe([
                'schema' => 2,
                'reservations' => [
                    'NCK-101:'.str_repeat('1', 32) => [
                        'operation_id' => str_repeat('1', 32),
                        'slots' => 3,
                        'network_slot' => 2,
                    ],
                    'NCK-102:'.str_repeat('2', 32) => [
                        'operation_id' => str_repeat('2', 32),
                        'slots' => 3,
                        'network_slot' => 3,
                    ],
                ],
            ]);
    });

    it('reuses only the exact retained reservation for interrupted acquisition', function (): void {
        [$capacity] = hostCapacity();
        $operation = new OperationId(str_repeat('4', 32));
        $attempt = attemptFixture('4');

        $first = $capacity->reserve('NCK-201', $attempt, $operation);
        $retained = $capacity->reserve('NCK-201', $attempt, $operation);

        expect([$first, $retained])
            ->toBe([2, 2])
            ->and(fn () => $capacity->reserve('NCK-201', $attempt, new OperationId(str_repeat('5', 32))))
            ->toThrow(RuntimeException::class, 'another acquisition operation');
    });

    it('refuses a second attempt while one attempt of the issue holds capacity', function (): void {
        [$capacity] = hostCapacity();
        $capacity->reserve('NCK-211', attemptFixture('4'), new OperationId(str_repeat('4', 32)));

        expect(fn () => $capacity->reserve('NCK-211', attemptFixture('5'), new OperationId(str_repeat('5', 32))))
            ->toThrow(RuntimeException::class, 'another attempt of this issue');
    });

    it('releases only an exact issue and acquisition owner', function (): void {
        [$capacity, $store] = hostCapacity(maxVms: 6);
        $operation = new OperationId(str_repeat('6', 32));
        $attempt = attemptFixture('6');
        $capacity->reserve('NCK-301', $attempt, $operation);

        expect(fn () => $capacity->release('NCK-301', $attempt, new OperationId(str_repeat('7', 32))))
            ->toThrow(RuntimeException::class, 'capacity reservation ownership does not match');

        $capacity->release('NCK-301', attemptFixture('7'), $operation);

        expect($store->read('capacity/incus.json')['reservations'])->toHaveCount(1);

        $capacity->release('NCK-301', $attempt, $operation);
        $capacity->release('NCK-301', $attempt, $operation);

        expect($store->read('capacity/incus.json'))->toBe([
            'schema' => 2,
            'reservations' => [],
        ]);
    });

    it('never reclaims a retained reservation from age alone', function (): void {
        [$capacity, $store] = hostCapacity(maxVms: 6);
        $operation = new OperationId(str_repeat('8', 32));
        $capacity->reserve('NCK-401', attemptFixture('8'), $operation);
        $ledger = $store->read('capacity/incus.json');

        expect($ledger['reservations']['NCK-401:'.str_repeat('8', 32)])
            ->toBe(['operation_id' => str_repeat('8', 32), 'slots' => 3, 'network_slot' => 2])
            ->not->toHaveKeys(['created_at', 'expires_at', 'pid']);
        expect(fn () => $capacity->reserve('NCK-402', attemptFixture('9'), new OperationId(str_repeat('9', 32))))
            ->toThrow(RuntimeException::class, 'Incus host capacity is exhausted.');
    });

    it('rejects duplicate retained network slots', function (): void {
        [$capacity, $store] = hostCapacity();
        $store->write('capacity/incus.json', [
            'schema' => 2,
            'reservations' => [
                'NCK-501:'.str_repeat('1', 32) => [
                    'operation_id' => str_repeat('1', 32),
                    'slots' => 3,
                    'network_slot' => 2,
                ],
                'NCK-502:'.str_repeat('2', 32) => [
                    'operation_id' => str_repeat('2', 32),
                    'slots' => 3,
                    'network_slot' => 2,
                ],
            ],
        ]);

        expect(fn () => $capacity->reserve('NCK-503', attemptFixture('3'), new OperationId(str_repeat('3', 32))))
            ->toThrow(RuntimeException::class, 'capacity ledger is invalid');
    });

    it('rejects a reservation key without an exact issue and attempt', function (string $key): void {
        [$capacity, $store] = hostCapacity();
        $store->write('capacity/incus.json', [
            'schema' => 2,
            'reservations' => [
                $key => ['operation_id' => str_repeat('1', 32), 'slots' => 3, 'network_slot' => 2],
            ],
        ]);

        expect(fn () => $capacity->reserve('NCK-503', attemptFixture('3'), new OperationId(str_repeat('3', 32))))
            ->toThrow(RuntimeException::class, 'capacity ledger is invalid');
    })->with([
        'issue only' => ['NCK-501'],
        'loose issue' => ['nck-501:'.str_repeat('1', 32)],
        'loose attempt' => ['NCK-501:'.str_repeat('A', 32)],
        'short attempt' => ['NCK-501:'.str_repeat('1', 31)],
    ]);

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

        expect($capacity->reserve('NCK-601', attemptFixture('a'), new OperationId(str_repeat('a', 32))))
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

        expect($capacity->reserve('NCK-602', attemptFixture('b'), new OperationId(str_repeat('b', 32))))->toBe(3);
    });

    it('rejects malformed relevant deterministic network addresses', function (): void {
        Process::fake(function (\Illuminate\Process\PendingProcess $process) {
            return Process::result(json_encode([
                ['name' => 'broken-net', 'config' => ['ipv4.address' => '10.232.2.1/16']],
            ], JSON_THROW_ON_ERROR));
        });
        [$capacity] = hostCapacity(host: new IncusHost(remote: 'lab', project: 'orbit'));

        expect(fn () => $capacity->reserve('NCK-603', attemptFixture('c'), new OperationId(str_repeat('c', 32))))
            ->toThrow(RuntimeException::class, 'malformed deterministic IPv4 address');
    });
});
