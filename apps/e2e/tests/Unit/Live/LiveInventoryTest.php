<?php

declare(strict_types=1);

use Tests\Live\Support\LiveInventory;

it('projects instances and networks to their stable identity fields', function (): void {
    $instances = [
        [
            'name' => 'orbit-e2e-topology-snapshot-gateway',
            'type' => 'virtual-machine',
            'status' => 'Stopped',
            'project' => 'default',
            'config' => [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'volatile.eth0.hwaddr' => '00:16:3e:aa:bb:cc',
                'volatile.last_state.power' => 'STOPPED',
            ],
            'expanded_devices' => ['eth0' => ['type' => 'nic', 'network' => 'oe-topo-snap']],
            'state' => ['memory' => ['usage' => 12345]],
            'last_used_at' => '2026-08-30T10:00:00Z',
        ],
    ];
    $networks = [
        [
            'name' => 'oe-topo-snap',
            'type' => 'bridge',
            'managed' => true,
            'config' => ['ipv4.address' => '10.232.0.1/24', 'volatile.bridge.hwaddr' => '00:16:3e:11:22:33'],
            'used_by' => ['/1.0/instances/orbit-e2e-topology-snapshot-gateway'],
        ],
        ['name' => 'lo', 'type' => 'loopback', 'managed' => false, 'config' => [], 'used_by' => []],
    ];

    expect(LiveInventory::fingerprint($instances, $networks))->toBe([
        'instances' => [
            'orbit-e2e-topology-snapshot-gateway' => [
                'type' => 'virtual-machine',
                'status' => 'Stopped',
                'project' => 'default',
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
                'devices' => ['eth0' => ['type' => 'nic', 'network' => 'oe-topo-snap']],
            ],
        ],
        'networks' => [
            'lo' => ['type' => 'loopback', 'managed' => false, 'config' => []],
            'oe-topo-snap' => ['type' => 'bridge', 'managed' => true, 'config' => ['ipv4.address' => '10.232.0.1/24']],
        ],
    ]);
});

it('sorts resources by name so ordering differences never count as change', function (): void {
    $first = LiveInventory::fingerprint(
        [['name' => 'b', 'type' => 'container'], ['name' => 'a', 'type' => 'container']],
        [],
    );
    $second = LiveInventory::fingerprint(
        [['name' => 'a', 'type' => 'container'], ['name' => 'b', 'type' => 'container']],
        [],
    );

    expect($first)->toBe($second)->and(array_keys($first['instances']))->toBe(['a', 'b']);
});

it('rejects a resource without a string name', function (): void {
    LiveInventory::fingerprint([['type' => 'container']], []);
})->throws(InvalidArgumentException::class, 'name');

it('names the resources of one attempt exactly', function (): void {
    $names = LiveInventory::observedNames(
        [['name' => 'orbit-e2e-nck-1-aaaaaaaa-gateway'], ['name' => 'other']],
        [['name' => 'oe-aaaaaaaaaaaa'], ['name' => 'oe-topo-snap']],
        ['orbit-e2e-nck-1-aaaaaaaa-gateway', 'orbit-e2e-nck-1-aaaaaaaa-app-dev', 'oe-aaaaaaaaaaaa'],
    );

    expect($names)->toBe(['orbit-e2e-nck-1-aaaaaaaa-gateway', 'oe-aaaaaaaaaaaa']);
});
