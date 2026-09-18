<?php

declare(strict_types=1);

use App\Domain\Nodes\Metrics\NodeFleetMetricsReader;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Orbit\Sdk\Requests\Metrics\ListMetricsNodesRequest;

it('reports available Nodes, no_samples, and no_exporter, excluding ineligible Nodes', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
        'user' => 'orbit',
    ]));
    $metricsNode = Node::query()->create([
        'name' => 'metrics',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
        'user' => 'orbit',
    ]);
    $metricsNode->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
    $available = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.3',
        'wireguard_ip' => '10.44.0.3',
        'user' => 'orbit',
    ]);
    $available->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $pending = Node::query()->create([
        'name' => 'app-prod',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.4',
        'wireguard_ip' => '10.44.0.4',
        'user' => 'orbit',
    ]);
    $pending->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $pinnedNoRole = Node::query()->create([
        'name' => 'bootstrap-only',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.5',
        'wireguard_ip' => '10.44.0.5',
        'user' => 'orbit',
        'ssh_host_fingerprint' => 'ssh-ed25519 AAAAsentinel',
    ]);
    $ineligible = Node::query()->create([
        'name' => 'provisioning-only',
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.6',
        'user' => 'orbit',
    ]);

    app()->instance(NodeFleetMetricsReader::class, new FakeNodeFleetMetricsReader($metricsNode, [
        'app-dev' => [
            'cores' => [0.1, 0.2],
            'memory' => ['used' => 1_073_741_824, 'total' => 4_294_967_296],
            'swap' => ['used' => 0, 'total' => 0],
            'load' => ['one' => 0.1, 'five' => 0.2, 'fifteen' => 0.3],
            'uptime_seconds' => 3661,
            'pressure' => [
                'cpu' => ['some_avg10' => 0.5],
                'memory' => ['some_avg10' => 0.0],
                'io' => ['some_avg10' => 1.2],
            ],
            'disks' => [
                ['mount' => '/', 'used' => 10_737_418_240, 'total' => 85_899_345_920],
            ],
        ],
    ]));

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/metrics/nodes')
        ->assertOk();

    $byName = collect($response->json('data'))->keyBy('node_name');

    expect($byName->keys()->all())
        ->not->toContain('provisioning-only')
        ->and($byName->has('bootstrap-only'))->toBeTrue();

    expect($byName['app-dev'])
        ->toMatchArray(['available' => true, 'reason' => null])
        ->and($byName['app-dev']['cores'])->toBe([0.1, 0.2])
        ->and($byName['app-dev']['memory']['used'])->toBe(1_073_741_824);

    expect($byName['app-prod'])->toMatchArray(['available' => false, 'reason' => 'no_samples']);
    expect($byName['bootstrap-only'])->toMatchArray(['available' => false, 'reason' => 'no_exporter']);
    expect($byName['metrics'])->toMatchArray(['available' => false, 'reason' => 'no_samples']);

    expect($ineligible->id)->not->toBeNull();
});

it('only reports Nodes the caller can access', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
        'user' => 'orbit',
    ]));
    $accessible = Node::query()->create([
        'name' => 'accessible',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
        'user' => 'orbit',
    ]);
    $accessible->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $restricted = Node::query()->create([
        'name' => 'restricted',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.3',
        'wireguard_ip' => '10.44.0.3',
        'user' => 'orbit',
    ]);
    $restricted->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $caller = Node::query()->create([
        'name' => 'caller',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.4',
        'wireguard_ip' => '10.44.0.4',
        'user' => 'orbit',
    ]);
    $caller->accessibleNodes()->attach($accessible);

    app()->instance(NodeFleetMetricsReader::class, new FakeNodeFleetMetricsReader($gateway, []));

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->getJson('/api/v1/metrics/nodes')
        ->assertOk();

    expect(collect($response->json('data'))->pluck('node_name')->all())->toBe(['accessible']);
});

it('rejects unauthorized fleet metrics requests', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
        'user' => 'orbit',
    ]));
    $noEdgeConsumer = Node::query()->create([
        'name' => 'no-edge-consumer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
        'user' => 'orbit',
    ]);

    app()->instance(NodeFleetMetricsReader::class, new FakeNodeFleetMetricsReader($gateway, []));

    $this
        ->withServerVariables(['REMOTE_ADDR' => $noEdgeConsumer->wireguard_ip])
        ->getJson('/api/v1/metrics/nodes')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');
});

/**
 * Records the fleet metrics response the CLI replays for `metrics:node:list`.
 */
it('records the metrics node list response', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
        'user' => 'orbit',
    ]));
    $available = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
        'user' => 'orbit',
    ]);
    $available->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $pending = Node::query()->create([
        'name' => 'app-prod',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.3',
        'wireguard_ip' => '10.44.0.3',
        'user' => 'orbit',
    ]);
    $pending->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);

    app()->instance(NodeFleetMetricsReader::class, new FakeNodeFleetMetricsReader($gateway, [
        'app-dev' => [
            'cores' => [0.12, 0.34, 0.08, 0.21],
            'memory' => ['used' => 3_435_973_836, 'total' => 8_589_934_592],
            'swap' => ['used' => 0, 'total' => 2_147_483_648],
            'load' => ['one' => 0.52, 'five' => 0.61, 'fifteen' => 0.58],
            'uptime_seconds' => 1_053_784,
            'pressure' => [
                'cpu' => ['some_avg10' => 0.4],
                'memory' => ['some_avg10' => 0.0],
                'io' => ['some_avg10' => 1.1],
            ],
            'disks' => [
                ['mount' => '/', 'used' => 6_442_450_944, 'total' => 85_899_345_920],
            ],
        ],
    ]));

    record_fixture(
        $this
            ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
            ->withHeader('X-Orbit-Request-Id', fixture_request_id())
            ->getJson('/api/v1/metrics/nodes')
            ->assertOk(),
        'metrics/node-list/default',
        ListMetricsNodesRequest::class,
        'GET /api/v1/metrics/nodes',
    );
});
