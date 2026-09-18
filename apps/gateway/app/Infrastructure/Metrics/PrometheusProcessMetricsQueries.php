<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

/**
 * The two instant PromQL queries `PrometheusProcessUsageIndex` needs, one per cAdvisor metric kind
 * it keeps (see `MetricsFootprint::CadvisorDisabledMetrics`): cAdvisor labels every series by the
 * cgroup's `name`, which for a Process is exactly `SystemdProcessRenderer::unitName()` or
 * `DockerProcessRenderer::containerName()` — both start with `orbit-process-`, so one regex covers
 * every runtime in one query.
 */
final readonly class PrometheusProcessMetricsQueries
{
    /**
     * The window `cpu()`'s rate() covers. cAdvisor is scraped every
     * `PrometheusConfigRenderer::CadvisorScrapeInterval` (30s), so this holds four scrapes — the
     * same margin `PrometheusMetricsQueries::RateWindow` keeps over the node exporter's own,
     * faster scrape (see `PrometheusMetricsQueriesTest`).
     */
    public const string CpuRateWindow = '120s';

    private const string NAME_PATTERN = 'orbit-process-.*';

    /** A Process's CPU usage, as a ratio of one core (matching `NodeMetricsResponse::$cores`). */
    public static function cpu(): string
    {
        return 'rate(container_cpu_usage_seconds_total{name=~"'.self::NAME_PATTERN.'"}['.self::CpuRateWindow.'])';
    }

    /** A Process's resident memory, in bytes. */
    public static function memory(): string
    {
        return 'container_memory_usage_bytes{name=~"'.self::NAME_PATTERN.'"}';
    }
}
