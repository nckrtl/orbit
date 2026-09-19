<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

/**
 * The two instant PromQL queries `PrometheusProcessUsageIndex` needs, one per cAdvisor metric kind
 * it keeps (see `MetricsFootprint::CadvisorDisabledMetrics`).
 *
 * cAdvisor labels the two runtimes differently, so each query has to ask for both. A container gets
 * a `name` label holding `DockerProcessRenderer::containerName()`, and its `id` is the opaque
 * `/system.slice/docker-{sha}.scope` cgroup path, which carries nothing identifying. A systemd unit
 * is a raw cgroup: cAdvisor gives it no `name` at all, only the `id` path ending in
 * `SystemdProcessRenderer::unitName()`, as in `/system.slice/orbit-process-69-agentation.service`.
 * Matching on `name` alone therefore finds containers and silently misses every systemd Process,
 * which is what these `or` arms exist to prevent.
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

    /** A container's `name`, which is `DockerProcessRenderer::containerName()` exactly. */
    private const string CONTAINER_SELECTOR = 'name=~"orbit-process-.*"';

    /**
     * A systemd unit's cgroup path, whose last segment is `SystemdProcessRenderer::unitName()`.
     *
     * The literal dot is written `\\.` because a PromQL string is Go-quoted before it is compiled
     * as a regex: a lone `\.` is not a legal Go escape, and Prometheus rejects the whole query with
     * `unknown escape sequence` rather than treating it as the regex escape it looks like.
     */
    private const string UNIT_SELECTOR = 'id=~".*/orbit-process-.*\\\\.service"';

    /** A Process's CPU usage, as a ratio of one core (matching `NodeMetricsResponse::$cores`). */
    public static function cpu(): string
    {
        $rate = static fn (string $selector): string => 'rate(container_cpu_usage_seconds_total{'
            .$selector.'}['.self::CpuRateWindow.'])';

        return $rate(self::CONTAINER_SELECTOR).' or '.$rate(self::UNIT_SELECTOR);
    }

    /** A Process's resident memory, in bytes. */
    public static function memory(): string
    {
        return 'container_memory_usage_bytes{'.self::CONTAINER_SELECTOR.'}'
            .' or container_memory_usage_bytes{'.self::UNIT_SELECTOR.'}';
    }
}
