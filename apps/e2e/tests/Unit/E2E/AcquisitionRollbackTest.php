<?php

declare(strict_types=1);

use App\E2E\AcquisitionRollback;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyTarget;

it('uses one inventory and parallel mutation batches before deleting the network', function (): void {
    $target = featureTarget('NCK-123');
    $operation = new OperationId(str_repeat('a', 32));
    $resources = [$target->network(), $target->instance('gateway'), $target->instance('app-dev')];
    $metadata = [
        'user.orbit.e2e.owner' => 'orbit-e2e',
        'user.orbit.e2e.issue' => 'NCK-123',
        'user.orbit.e2e.attempt' => $target->requireAttempt()->value,
        'user.orbit.e2e.operation' => $operation->value,
    ];
    $inventory = [
        $resources[0] => new IncusNetwork('lab', 'orbit', $resources[0], $metadata),
        $resources[1] => new IncusInstance(
            'lab',
            'orbit',
            $resources[1],
            'pool',
            $metadata,
            'RUNNING',
            103,
            $target->network(),
            $target->mac('gateway'),
        ),
        $resources[2] => new IncusInstance(
            'lab',
            'orbit',
            $resources[2],
            'pool',
            $metadata,
            'STOPPED',
            102,
            $target->network(),
            $target->mac('app-dev'),
        ),
    ];
    $calls = [];
    $identity = static fn ($resource): array => [
        'remote' => $resource->remote,
        'project' => $resource->project,
        'name' => $resource->name,
        'pool' => $resource instanceof IncusInstance ? $resource->pool : null,
        'network' => $resource instanceof IncusInstance ? $resource->network : null,
        'mac' => $resource instanceof IncusInstance ? $resource->mac : null,
        'metadata' => $resource->metadata,
    ];
    $rollback = new AcquisitionRollback(
        static function (array $names) use (&$inventory): array {
            return array_intersect_key($inventory, array_flip($names));
        },
        function (array $names) use (&$calls): void {
            $calls[] = ['stop', $names];
        },
        function (array $names) use (&$calls, &$inventory): void {
            $calls[] = ['delete', $names];
            foreach ($names as $name) {
                $inventory[$name] = null;
            }
        },
        function (string $name) use (&$calls, &$inventory): void {
            $calls[] = ['network', $name];
            $inventory[$name] = null;
        },
    );
    $result = $rollback->cleanup($target, $resources, array_map($identity, $inventory), $operation);
    expect($result)
        ->toEqual(array_fill_keys($resources, 'removed'))
        ->and($calls)
        ->toBe([
            ['stop', [$resources[1]]],
            ['delete', [$resources[1], $resources[2]]],
            ['network', $resources[0]],
        ]);
});

it('fails closed when the batch inventory is incomplete', function (): void {
    $target = featureTarget('NCK-123');
    $resources = [$target->network(), $target->instance('gateway')];
    $calls = 0;
    $rollback = new AcquisitionRollback(
        fn () => [$resources[0] => null],
        fn () => $calls++,
        fn () => $calls++,
        fn () => $calls++,
    );
    $result = $rollback->cleanup($target, $resources, [], new OperationId(str_repeat('b', 32)));
    expect($result)->each->toStartWith('failed:')->and($calls)->toBe(0);
});

it('retains the network and every VM result when a VM batch mutation fails', function (string $failure): void {
    $target = featureTarget('NCK-123');
    $operation = new OperationId(str_repeat('a', 32));
    $resources = [$target->network(), $target->instance('gateway'), $target->instance('app-dev')];
    $metadata = [
        'user.orbit.e2e.owner' => 'orbit-e2e',
        'user.orbit.e2e.issue' => $target->issue,
        'user.orbit.e2e.attempt' => $target->requireAttempt()->value,
        'user.orbit.e2e.operation' => $operation->value,
    ];
    $inventory = [
        $resources[0] => new IncusNetwork('lab', 'orbit', $resources[0], $metadata),
        $resources[1] => new IncusInstance(
            'lab',
            'orbit',
            $resources[1],
            'pool',
            $metadata,
            'RUNNING',
            103,
            $target->network(),
            $target->mac('gateway'),
        ),
        $resources[2] => new IncusInstance(
            'lab',
            'orbit',
            $resources[2],
            'pool',
            $metadata,
            network: $target->network(),
            mac: $target->mac('app-dev'),
        ),
    ];
    $identity = static fn (IncusInstance|IncusNetwork $resource): array => [
        'remote' => $resource->remote,
        'project' => $resource->project,
        'name' => $resource->name,
        'pool' => $resource instanceof IncusInstance ? $resource->pool : null,
        'network' => $resource instanceof IncusInstance ? $resource->network : null,
        'mac' => $resource instanceof IncusInstance ? $resource->mac : null,
        'metadata' => $resource->metadata,
    ];
    $networkDeletes = 0;
    $rollback = new AcquisitionRollback(
        fn (array $names): array => $inventory,
        $failure === 'stop'
            ? fn () => throw new RuntimeException('stop batch failed')
            : fn () => null,
        $failure === 'delete'
            ? fn () => throw new RuntimeException('delete batch failed')
            : fn () => null,
        function () use (&$networkDeletes): void {
            $networkDeletes++;
        },
    );

    $result = $rollback->cleanup($target, $resources, array_map($identity, $inventory), $operation);

    expect($result)
        ->toHaveKeys($resources)
        ->and($result[$target->network()])
        ->toBe('retained_due_to_vm_'.$failure.'_failure')
        ->and($networkDeletes)
        ->toBe(0);
})->with(['stop', 'delete']);
