<?php

declare(strict_types=1);

use App\Domain\Nodes\Metrics\NodeFleetMetricsReader;
use App\Domain\Nodes\Metrics\NodeFleetMetricsSnapshot;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\Metrics\NodeMetricsPrometheusReader;
use App\Models\Node;

final readonly class NodeMetricsPrometheusReaderFleetFake implements NodeFleetMetricsReader
{
    /** @param array<string, array<string, mixed>> $snapshots */
    public function __construct(private Node $metricsNode, private array $snapshots) {}

    public function read(): NodeFleetMetricsSnapshot
    {
        return new NodeFleetMetricsSnapshot($this->metricsNode, $this->snapshots);
    }
}

it('returns the raw snapshot for a Node Prometheus has sampled', function (): void {
    $metricsNode = new Node(['name' => 'metrics', 'status' => LifecycleStatus::Active]);
    $node = new Node(['name' => 'app-dev', 'status' => LifecycleStatus::Active]);
    $snapshot = ['cores' => [0.1], 'memory' => ['used' => 1, 'total' => 2]];

    $reader = new NodeMetricsPrometheusReader(new NodeMetricsPrometheusReaderFleetFake($metricsNode, ['app-dev' => $snapshot]));

    expect($reader->read($node))->toBe($snapshot);
});

it('answers node.metrics_unreachable for a Node with no Prometheus samples', function (): void {
    $metricsNode = new Node(['name' => 'metrics', 'status' => LifecycleStatus::Active]);
    $node = new Node(['name' => 'never-scraped', 'status' => LifecycleStatus::Active]);

    $reader = new NodeMetricsPrometheusReader(new NodeMetricsPrometheusReaderFleetFake($metricsNode, []));

    try {
        $reader->read($node);
        $this->fail('Expected a ResourceOperationException.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('node.metrics_unreachable')
            ->and($exception->status)->toBe(502);
    }
});
