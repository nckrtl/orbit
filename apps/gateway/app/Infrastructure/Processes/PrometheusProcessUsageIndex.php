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
 * One CPU query and one memory query (see `PrometheusProcessMetricsQueries`) cover the whole fleet,
 * and the two lookups this class builds from them are keyed by `orbit-process-{id}-{name}` for a
 * container and by `orbit-process-{id}-{name}.service` for a unit — the latter the same key
 * `PrometheusProcessRuntimeStatusIndex` uses for its unit states.
 *
 * Reading that key back out of a series takes both labels, because cAdvisor only labels containers
 * by `name`. A systemd unit is a raw cgroup with no `name` at all, so its key is the last segment
 * of the `id` path (`/system.slice/orbit-process-69-agentation.service`). Keying on `name` alone
 * leaves every systemd Process reporting no usage while containers report theirs.
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
     * The key cAdvisor would report this Process's cgroup under, or null when it cannot be rendered
     * (an unpersisted or malformed Process, which has no cgroup to measure anyway).
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

    /** @return array<string, float|int> Value keyed by container name or systemd unit name. */
    private function series(string $promql): array
    {
        $values = [];

        foreach ($this->prometheus->series($promql) as $entry) {
            $metric = $entry['metric'] ?? null;
            $name = is_array($metric) ? self::key($metric) : null;

            if ($name === null) {
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

    /**
     * One series' lookup key: a container's `name`, or the unit name a raw cgroup's `id` path ends
     * in. Anything else — a cgroup that is neither, or a series carrying neither label — has no key
     * and is skipped, so an unrelated series can never be read as some Process's usage.
     *
     * @param  array<string, mixed>  $metric
     */
    private static function key(array $metric): ?string
    {
        $name = $metric['name'] ?? null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $id = $metric['id'] ?? null;

        if (! is_string($id)) {
            return null;
        }

        $unit = substr($id, (int) strrpos($id, '/') + 1);

        return str_starts_with($unit, 'orbit-process-') ? $unit : null;
    }
}
