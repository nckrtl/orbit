<?php

declare(strict_types=1);

use App\Domain\Nodes\Metrics\NodeMetricsReader;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

final class FakeNodeMetricsReader implements NodeMetricsReader
{
    /** @param array<string, mixed>|null $snapshot */
    public function __construct(private readonly ?array $snapshot = null) {}

    public function read(Node $node): array
    {
        return $this->snapshot ?? [
            'cores' => [0.1, 0.2],
            'memory' => ['used' => 1_073_741_824, 'total' => 4_294_967_296],
            'swap' => ['used' => 0, 'total' => 2_147_483_648],
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
        ];
    }
}

it('returns one metrics snapshot for an active node', function (): void {
    app()->instance(NodeMetricsReader::class, new FakeNodeMetricsReader);

    $node = $this->markAsGateway(Node::query()->create([
        'name' => 'metrics-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.230',
        'wireguard_ip' => '10.44.0.230',
        'user' => 'orbit',
    ]));

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->getJson("/api/v1/nodes/{$node->id}/metrics");

    $response
        ->assertOk()
        ->assertJsonPath('data.node_id', $node->id)
        ->assertJsonPath('data.node_name', 'metrics-node')
        ->assertJsonPath('data.cores', [0.1, 0.2])
        ->assertJsonPath('data.memory.used', 1_073_741_824)
        ->assertJsonPath('data.memory.total', 4_294_967_296)
        ->assertJsonPath('data.load.one', 0.1)
        ->assertJsonPath('data.uptime_seconds', 3661)
        ->assertJsonPath('data.pressure.cpu.some_avg10', 0.5)
        ->assertJsonPath('data.disks.0.mount', '/');
});

it('refuses metrics for a node that is not active or has no WireGuard address', function (): void {
    app()->instance(NodeMetricsReader::class, new FakeNodeMetricsReader);

    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'metrics-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.231',
        'wireguard_ip' => '10.44.0.231',
        'user' => 'orbit',
    ]));

    $inactive = Node::query()->create([
        'name' => 'metrics-inactive',
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.232',
        'user' => 'orbit',
    ]);
    $gateway->accessibleNodes()->attach($inactive->id);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson("/api/v1/nodes/{$inactive->id}/metrics")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'node.metrics_unavailable');
});

it('answers a reader failure as an unreachable node', function (): void {
    app()->instance(NodeMetricsReader::class, new class implements NodeMetricsReader
    {
        public function read(Node $node): array
        {
            throw new ResourceOperationException(
                errorCode: 'node.metrics_unreachable',
                message: "Node [{$node->name}] metrics could not be read.",
                status: 502,
            );
        }
    });

    $node = $this->markAsGateway(Node::query()->create([
        'name' => 'metrics-unreachable',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.233',
        'wireguard_ip' => '10.44.0.233',
        'user' => 'orbit',
    ]));

    $this
        ->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->getJson("/api/v1/nodes/{$node->id}/metrics")
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node.metrics_unreachable');
});
