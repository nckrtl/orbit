<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

/**
 * The compact CPU, memory, swap, and disk snapshot `orbit top`'s dashboard draws for every Node
 * at once, from `GET /metrics/nodes`. One request refreshes the whole fleet instead of one
 * request per Node (see `Sources\NodeMetricsSource`, used for a single open Node page).
 */
interface FleetNodeMetricsSource
{
    /**
     * @return array<int, array{
     *     cores: list<float>,
     *     mem: array{float, float},
     *     swap: array{float, float},
     *     uptime: string,
     *     disks: list<array{string, float, float}>,
     * }|null> Keyed by Node id. A Node the Gateway reports as unavailable, or one it left out of
     *         the response, is absent here; State keeps that Node's last known metrics (or none)
     *         rather than treating a partial response as "no metrics for everyone".
     */
    public function forFleet(): array;
}
