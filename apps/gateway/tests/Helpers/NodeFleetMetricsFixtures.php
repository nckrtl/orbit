<?php

declare(strict_types=1);

use App\Domain\Nodes\Metrics\NodeFleetMetricsReader;
use App\Domain\Nodes\Metrics\NodeFleetMetricsSnapshot;
use App\Models\Node;

/**
 * A `NodeFleetMetricsReader` that always answers the given raw snapshots, keyed by Node name,
 * without an SSH connection or a Prometheus response to fake. `ListFleetNodeMetricsAction` still
 * runs its own eligibility, selection, and Node access filtering against the real database, so
 * this only replaces the Prometheus round trip.
 */
final class FakeNodeFleetMetricsReader implements NodeFleetMetricsReader
{
    /** @param array<string, array<string, mixed>> $snapshots */
    public function __construct(
        private readonly Node $metricsNode,
        private readonly array $snapshots,
    ) {}

    public function read(): NodeFleetMetricsSnapshot
    {
        return new NodeFleetMetricsSnapshot($this->metricsNode, $this->snapshots);
    }
}
