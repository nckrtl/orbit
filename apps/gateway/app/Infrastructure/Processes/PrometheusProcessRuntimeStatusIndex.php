<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Infrastructure\Metrics\GrafanaPrometheusClient;
use App\Models\Process;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reads every systemd Process's live state from the Metrics role's Prometheus in one query,
 * instead of asking each Process's own Node over SSH.
 *
 * The Metrics role's node exporter already scrapes `node_systemd_unit_state` every few seconds,
 * and a Process's unit name is derived from its own id and name, so the whole fleet's runtime
 * state is one instant query and a lookup. Measured before this existed, a fleet-wide list cost
 * about ten seconds and one SSH round trip per Process; the Gateway holds no sample of its own.
 *
 * The metric reports the same words `systemctl is-active` does, one series per state with a
 * value of 1 on the current one, so a status read this way is the status a caller already knows.
 * A unit Prometheus has no series for is reported `inactive`: systemd keeps no cgroup for a
 * stopped unit, and the exporter only lists units systemd currently knows about.
 *
 * Docker Processes are not in this metric. Until container metrics are scraped, those fall back
 * to `ProcessRuntimeManager::status()`, which is one SSH round trip each; the fleet runs two of
 * them against twenty systemd units, so the fan-out this class removes is the one that mattered.
 */
final readonly class PrometheusProcessRuntimeStatusIndex implements ProcessRuntimeStatusIndex
{
    /** Every state `node_systemd_unit_state` reports, which are `systemctl is-active`'s words. */
    private const string UNIT_STATE_QUERY = 'node_systemd_unit_state{name=~"orbit-process-.*"} == 1';

    public function __construct(
        private GrafanaPrometheusClient $prometheus,
        private ProcessRuntimeManager $runtime,
        private SystemdProcessRenderer $systemd,
    ) {}

    #[\Override]
    public function statuses(Collection $processes): array
    {
        $systemd = $processes->contains(static fn (Process $process): bool => $process->runtime === ProcessRuntime::Systemd);
        $states = $systemd ? $this->unitStates() : [];
        $statuses = [];

        foreach ($processes as $process) {
            $statuses[(int) $process->id] = $process->runtime === ProcessRuntime::Systemd && $states !== null
                ? ($states[$this->systemd->unitName($process)] ?? 'inactive')
                : $this->fromNode($process);
        }

        return $statuses;
    }

    /**
     * Current state keyed by unit name, or null when Prometheus could not answer at all: a fleet
     * with no Metrics role, or one whose Grafana is unreachable, still lists its Processes by
     * asking each Node, which is what this class exists to avoid but is better than reporting
     * every unit stopped.
     *
     * @return array<string, string>|null
     */
    private function unitStates(): ?array
    {
        try {
            $series = $this->prometheus->query(self::UNIT_STATE_QUERY)['data']['result'] ?? null;
        } catch (Throwable) {
            return null;
        }

        if (! is_array($series)) {
            return null;
        }

        $states = [];

        foreach ($series as $entry) {
            $metric = is_array($entry) ? ($entry['metric'] ?? null) : null;

            if (! is_array($metric)) {
                continue;
            }

            $name = $metric['name'] ?? null;
            $state = $metric['state'] ?? null;

            if (is_string($name) && is_string($state)) {
                $states[$name] = $state;
            }
        }

        return $states;
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
