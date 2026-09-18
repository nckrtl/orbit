<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Metrics;

use App\Models\Node;

final readonly class NodeFleetMetricsSnapshot
{
    /**
     * @param  array<string, array<string, mixed>>  $snapshots  Raw metrics snapshots keyed by
     *                                                          Node name, present only for a
     *                                                          Node Prometheus has samples for.
     */
    public function __construct(
        public Node $metricsNode,
        public array $snapshots,
    ) {}
}
