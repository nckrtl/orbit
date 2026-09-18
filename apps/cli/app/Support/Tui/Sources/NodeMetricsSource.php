<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

/**
 * The compact CPU, memory, swap, and disk snapshot `orbit top`'s dashboard and Node page draw
 * per node.
 *
 * The Gateway does not yet expose `GET /nodes/{node}/metrics`; a parallel slice is adding it
 * alongside a redis driver. `forNode()` returns `null` until then, and the metrics blocks render
 * "Metrics not available on this Gateway yet." instead of the bars. Wire the real SDK request by
 * replacing `NullNodeMetricsSource` with an implementation that calls the new request and maps
 * its response into this shape.
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
     * }|null Null when this Gateway cannot report metrics for this node yet.
     */
    public function forNode(int $nodeId): ?array;
}
