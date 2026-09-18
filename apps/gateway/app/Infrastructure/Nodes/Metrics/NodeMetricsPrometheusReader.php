<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Metrics;

use App\Domain\Nodes\Metrics\NodeFleetMetricsReader;
use App\Domain\Nodes\Metrics\NodeMetricsReader;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

/**
 * Reads one Node's metrics snapshot from the Metrics role's Prometheus, by way of the same fleet
 * read `ListFleetNodeMetricsAction` uses. See `PrometheusFleetMetricsSshReader` for how the
 * Gateway reaches Prometheus and `PrometheusNodeMetricsMapper` for how a response becomes a
 * snapshot.
 */
final readonly class NodeMetricsPrometheusReader implements NodeMetricsReader
{
    public function __construct(private NodeFleetMetricsReader $fleet) {}

    public function read(Node $node): array
    {
        $snapshot = $this->fleet->read()->snapshots[$node->name] ?? null;

        if ($snapshot === null) {
            throw new ResourceOperationException(
                errorCode: 'node.metrics_unreachable',
                message: "Node [{$node->name}] metrics could not be read.",
                status: 502,
            );
        }

        return $snapshot;
    }
}
