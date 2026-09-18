<?php

declare(strict_types=1);

namespace App\Support\Metrics;

/**
 * The four instant PromQL queries `PrometheusNodeMetricsMapper` expects, one per metric group.
 * Every metric a Linux or a Darwin `node_exporter` reports is matched by name, so the mapper can
 * tell which family an instance actually sent (see PrometheusNodeMetricsMapper). Pass `$instance`
 * (an already-validated `IP:9100` scrape target) to scope a query to one Node; omit it to cover
 * every instance Prometheus has samples for.
 */
final readonly class PrometheusMetricsQueries
{
    /** The label `pressure()` writes its kind into, and the mapper groups by. */
    public const string PRESSURE_KIND_LABEL = 'orbit_pressure';

    private const string SCALAR_NAMES = 'node_memory_MemTotal_bytes|node_memory_MemAvailable_bytes'
        .'|node_memory_SwapTotal_bytes|node_memory_SwapFree_bytes|node_memory_total_bytes|node_memory_free_bytes'
        .'|node_memory_active_bytes|node_memory_inactive_bytes|node_memory_wired_bytes|node_memory_compressed_bytes'
        .'|node_memory_purgeable_bytes|node_memory_internal_bytes|node_memory_swap_total_bytes'
        .'|node_memory_swap_used_bytes|node_load1|node_load5|node_load15|node_boot_time_seconds';

    public static function scalars(?string $instance = null): string
    {
        return '{__name__=~"'.self::SCALAR_NAMES.'"'.self::instanceClause($instance).'}';
    }

    public static function cores(?string $instance = null): string
    {
        return '1 - rate(node_cpu_seconds_total{mode="idle"'.self::instanceClause($instance).'}[1m])';
    }

    /**
     * PromQL drops `__name__` in a binary operation and set operators match on the labels that
     * remain, so a union of the three rates would collapse to whichever came first. Each rate
     * therefore carries its kind in a label of its own, which keeps the three label sets distinct
     * and gives the mapper something to group by.
     */
    public static function pressure(?string $instance = null): string
    {
        $clause = self::instanceClause($instance);

        return implode(' or ', array_map(
            static fn (string $kind): string => 'label_replace(rate(node_pressure_'.$kind
                .'_waiting_seconds_total{'.ltrim($clause, ',').'}[1m]) * 100,"'
                .self::PRESSURE_KIND_LABEL.'","'.$kind.'","","")',
            ['cpu', 'memory', 'io'],
        ));
    }

    public static function disks(?string $instance = null): string
    {
        return '{__name__=~"node_filesystem_size_bytes|node_filesystem_avail_bytes"'.self::instanceClause($instance).'}';
    }

    private static function instanceClause(?string $instance): string
    {
        return $instance === null ? '' : ',instance="'.$instance.'"';
    }
}
