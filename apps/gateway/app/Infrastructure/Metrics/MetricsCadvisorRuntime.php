<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Models\Node;

/**
 * Converges one Node's cAdvisor binary, unit, and firewall rule.
 *
 * Mirrors `MetricsExporterRuntime`'s shape so `NativeMetricsCadvisorLifecycle` can run the same
 * snapshot/converge/remove/restore fleet dance `NativeMetricsExporterLifecycle` runs for the node
 * exporter. There is no `actual()`: nothing surfaces per-node cAdvisor status today, so the drift
 * this executor already fails closed on (see `MetricsCadvisorSshExecutor::guardOwnership()`) is
 * the whole of it.
 */
interface MetricsCadvisorRuntime
{
    public function snapshot(Node $node, Node $metricsNode): MetricsExporterState;

    public function converge(Node $node, Node $metricsNode): void;

    public function remove(Node $node, Node $metricsNode): void;

    public function restore(Node $node, Node $metricsNode, MetricsExporterState $state): void;
}
