<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessUsageIndex;
use App\Infrastructure\Metrics\GrafanaPrometheusClient;
use App\Infrastructure\Metrics\PrometheusProcessMetricsQueries;
use App\Models\Process;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reads every Process's live CPU and memory from the Metrics role's cAdvisor, through the same
 * Grafana datasource proxy `PrometheusProcessRuntimeStatusIndex` reads runtime status through.
 *
 * cAdvisor keys every series by the cgroup's `name` label, which for a Process is exactly
 * `SystemdProcessRenderer::unitName()` or `DockerProcessRenderer::containerName()` — both are
 * `orbit-process-{id}-{name}[.service]`, so one CPU query and one memory query (see
 * `PrometheusProcessMetricsQueries`) cover the whole fleet, and the two lookups this class builds
 * from them are keyed the same way `PrometheusProcessRuntimeStatusIndex` keys its unit states.
 *
 * A Process cAdvisor has no series for — not running, cAdvisor unreachable, or the Metrics role is
 * not assigned — reports null for that field rather than zero: a stopped Process has no usage, and
 * a query that failed outright must never look identical to one that measured zero.
 */
final readonly class PrometheusProcessUsageIndex implements ProcessUsageIndex
{
    public function __construct(
        private GrafanaPrometheusClient $prometheus,
        private SystemdProcessRenderer $systemd,
        private DockerProcessRenderer $docker,
    ) {}

    #[\Override]
    public function usage(Collection $processes): array
    {
        $cpu = $this->series(PrometheusProcessMetricsQueries::cpu());
        $memory = $this->series(PrometheusProcessMetricsQueries::memory());
        $usage = [];

        foreach ($processes as $process) {
            $name = $this->name($process);
            $processMemory = $name === null ? null : ($memory[$name] ?? null);

            $usage[(int) $process->id] = [
                'cpu' => $name === null ? null : ($cpu[$name] ?? null),
                'memory_bytes' => $processMemory === null ? null : (int) round($processMemory),
            ];
        }

        return $usage;
    }

    /**
     * The cAdvisor `name` label value cAdvisor would report this Process's cgroup under, or null
     * when it cannot be rendered (an unpersisted or malformed Process, which has no cgroup to
     * measure anyway).
     */
    private function name(Process $process): ?string
    {
        try {
            return $process->runtime === ProcessRuntime::Docker
                ? $this->docker->containerName($process)
                : $this->systemd->unitName($process);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, float|int> Value keyed by the series' `name` label. */
    private function series(string $promql): array
    {
        $values = [];

        foreach ($this->prometheus->series($promql) as $entry) {
            $metric = $entry['metric'] ?? null;
            $name = is_array($metric) ? ($metric['name'] ?? null) : null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $sample = $entry['value'] ?? null;
            $raw = is_array($sample) ? ($sample[1] ?? null) : null;

            if (! is_numeric($raw)) {
                continue;
            }

            $values[$name] = (float) $raw;
        }

        return $values;
    }
}
