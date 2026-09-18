<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

/**
 * The compact CPU, memory, swap, and disk snapshot `orbit top`'s dashboard and Node page draw
 * per node.
 *
 * `Sources\GatewayNodeMetricsSource` calls `GET /nodes/{node}/metrics`. `forNode()` returns
 * `null` when the Gateway request fails (an older Gateway that does not expose metrics, for
 * example), and the metrics blocks render "Metrics not available on this Gateway yet." instead
 * of the bars. `State::nodeMetrics()` prefers a live `node.sample` realtime event over this
 * source, so a Gateway that streams samples but does not answer the metrics endpoint still
 * shows live numbers.
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
