<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

/**
 * The compact CPU, memory, swap, and disk snapshot `orbit top`'s Node page draws for one node.
 *
 * `Sources\GrafanaPrometheusMetricsSource` reads it through the Metrics role's Grafana. `forNode()`
 * returns `null` when Metrics is disabled, the credential is rejected, the Node has no WireGuard
 * address, or Prometheus has no samples for it, and the metrics blocks render "No metrics."
 * instead of the bars. `State::nodeMetrics()` prefers a live `node.sample` realtime event over
 * this source, so a Gateway that streams samples still shows live numbers.
 */
interface NodeMetricsSource
{
    /**
     * @return array{
     *     cores: list<float>,
     *     mem: array{float, float},
     *     swap: array{float, float},
     *     uptime: string,
     *     disks: list<array{string, float, float}>,
     * }|null Null when this Gateway cannot report metrics for this node.
     */
    public function forNode(int $nodeId): ?array;
}
