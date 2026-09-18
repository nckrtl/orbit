<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;
use App\Models\NodeRole;

/**
 * Converges cAdvisor across the same fleet `MetricsExporterLifecycle` converges the node exporter
 * on. There is no `targets()`: cAdvisor scrapes the same Nodes at the same addresses the node
 * exporter does, so `PrometheusConfigRenderer` reuses `MetricsExporterLifecycle::targets()` and
 * just scrapes a second port. There is no `actual()`: nothing surfaces per-node cAdvisor status.
 */
interface MetricsCadvisorLifecycle
{
    public function converge(Node $node, NodeRole $assignment): void;

    public function remove(Node $node, NodeRole $assignment): void;

    public function removeNode(Node $node, Node $metricsNode): void;
}
