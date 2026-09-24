<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Infrastructure\Metrics\GrafanaPrometheusClient;
use App\Models\Process;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Reads every Process's live state from the Metrics role's Prometheus in one query, instead of
 * asking each Process's own Node over SSH.
 *
 * The node exporter scrapes `node_systemd_unit_state`, and cAdvisor reports every running
 * container by its `name`. A Process's unit and container names derive from its id and name, so
 * the whole fleet's runtime state is one instant query and a lookup. The answer is cached for
 * `CacheSeconds`, so every open list shares one query.
 *
 * The metric reports the same words `systemctl is-active` does, one series per state with a
 * value of 1 on the current one. A unit Prometheus has no series for is reported `inactive`:
 * systemd keeps no cgroup for a stopped unit. cAdvisor reports only running containers, so a
 * Docker Process with a series is `running` and one without is `exited`, the words
 * `docker container inspect` uses.
 *
 * Only when Prometheus cannot answer at all does the index ask each Node, which is one SSH round
 * trip per Process and the fan-out this class exists to avoid.
 */
final readonly class PrometheusProcessRuntimeStatusIndex implements ProcessRuntimeStatusIndex
{
    /** How long one fleet-wide answer serves every caller; the scrapes it reads are no fresher. */
    public const int CacheSeconds = 10;

    /**
     * How long a status observed after a start, stop, or restart outranks Prometheus: one cached
     * answer plus one scrape, with margin, after which Prometheus has a sample from after the change.
     */
    public const int ObservedSeconds = 30;

    private const string CACHE_KEY = 'processes.runtime-states';

    private const string OBSERVED_KEY = 'processes.runtime-status.';

    /** Current unit states, and every running Orbit container, in one query. */
    private const string RUNTIME_QUERY = '(node_systemd_unit_state{name=~"orbit-process-.*"} == 1)'
        .' or container_last_seen{name=~"orbit-process-.*"}';

    public function __construct(
        private GrafanaPrometheusClient $prometheus,
        private ProcessRuntimeManager $runtime,
        private SystemdProcessRenderer $systemd,
        private DockerProcessRenderer $docker,
    ) {}

    #[\Override]
    public function statuses(Collection $processes): array
    {
        $states = $processes->isEmpty() ? [] : $this->runtimeStates();
        $observed = $processes->isEmpty()
            ? []
            : Cache::many($processes->map(static fn (Process $process): string => self::OBSERVED_KEY.$process->id)->all());
        $statuses = [];

        foreach ($processes as $process) {
            $recent = $observed[self::OBSERVED_KEY.$process->id] ?? null;
            $statuses[(int) $process->id] = match (true) {
                is_string($recent) => $recent,
                $states === null => $this->fromNode($process),
                default => $this->fromStates($process, $states),
            };
        }

        return $statuses;
    }

    #[\Override]
    public function remember(Process $process, string $status): void
    {
        Cache::put(self::OBSERVED_KEY.$process->id, $status, self::ObservedSeconds);
    }

    /**
     * Current state keyed by unit or container name, or null when Prometheus could not answer at
     * all: a fleet with no Metrics role, or one whose Grafana is unreachable, still lists its
     * Processes by asking each Node rather than reporting every one stopped. A failed read is
     * not cached.
     *
     * @return array<string, string>|null
     */
    private function runtimeStates(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            /** @var array<string, string> $cached */
            return $cached;
        }

        try {
            $series = $this->prometheus->query(self::RUNTIME_QUERY)['data']['result'] ?? null;
        } catch (Throwable) {
            return null;
        }

        if (! is_array($series)) {
            return null;
        }

        $states = [];

        foreach ($series as $entry) {
            $metric = is_array($entry) ? ($entry['metric'] ?? null) : null;
            $name = is_array($metric) ? ($metric['name'] ?? null) : null;

            if (! is_string($name)) {
                continue;
            }

            $state = $metric['state'] ?? 'running';

            if (is_string($state)) {
                $states[$name] = $state;
            }
        }

        Cache::put(self::CACHE_KEY, $states, self::CacheSeconds);

        return $states;
    }

    /** @param array<string, string> $states */
    private function fromStates(Process $process, array $states): string
    {
        try {
            return $process->runtime === ProcessRuntime::Systemd
                ? $states[$this->systemd->unitName($process)] ?? 'inactive'
                : (isset($states[$this->docker->containerName($process)]) ? 'running' : 'exited');
        } catch (InvalidArgumentException) {
            return 'unknown';
        }
    }

    private function fromNode(Process $process): string
    {
        try {
            return $this->runtime->status($process);
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
