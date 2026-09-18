<?php

declare(strict_types=1);

use App\Domain\Nodes\Metrics\NodeMetricsReader;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Orbit\Sdk\Requests\Nodes\ShowNodeMetricsRequest;

/**
 * Records the node metrics response that the CLI replays for `node:metrics`.
 */
it('records the node metrics response', function (): void {
    app()->instance(NodeMetricsReader::class, new class implements NodeMetricsReader
    {
        public function read(Node $node): array
        {
            return [
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
            ];
        }
    });

    $node = $this->markAsGateway(Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '94.237.40.75',
        'wireguard_ip' => '10.44.0.3',
        'user' => 'orbit',
    ]));

    record_fixture(
        $this
            ->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
            ->withHeader('X-Orbit-Request-Id', fixture_request_id())
            ->getJson("/api/v1/nodes/{$node->id}/metrics")
            ->assertOk(),
        'nodes/node-metrics/default',
        ShowNodeMetricsRequest::class,
        'GET /api/v1/nodes/{node}/metrics',
    );
});
