<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Data\Fleet\DesiredFleetStateData;
use App\Domain\Nodes\NodeCliInstallation;
use App\Domain\Nodes\NodeUpdateBroadcaster;
use App\Infrastructure\Nodes\NodeLocks;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use App\Models\Node;
use App\Models\NodeFootprint as NodeFootprintRecord;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Runs the fleet rollout and its catch-up (ADR 0202). `orbit-fleet-converge.service` runs it after each
 * verified Gateway release and every 5 minutes from its timer; it never runs inside PHP-FPM or the
 * scheduler.
 *
 * One run:
 *
 * 1. Stops when the rollout is disabled, another run holds the fleet lock, or a rollout is halted.
 * 2. Resolves the desired state of the running Gateway. Without a published CLI release it records
 *    the rollout as `waiting` and stops; it never halts for that. When the state falls back to an
 *    ancestor's release, the rollout uses it and raises `rollout_cli_fallback` once.
 * 3. Opens a rollout for a new commit, or refreshes the open one.
 * 4. Visits, one at a time in rollout order, each Node that is pending, unreachable, or deferred, and
 *    each converged Node whose footprint digest, agent version, or CLI version differs from the desired
 *    state. That second group is the catch-up.
 * 5. Halts at the first failed Node, leaves the remaining Nodes untouched, and raises the alert.
 */
final readonly class FleetRolloutRunner
{
    public const string LockName = 'fleet-rollout';

    /** How long a rollout may wait for its CLI release before it raises `rollout_stalled` once: 2 hours. */
    public const int WaitingAlertSeconds = 7200;

    public function __construct(
        private DesiredFleetState $desired,
        private FleetRolloutPlanner $planner,
        private FleetRolloutMembership $membership,
        private FleetNodeVisitor $converger,
        private FleetReleaseLag $lag,
        private FleetRolloutAlerts $alerts,
        private NodeLocks $locks,
        private bool $enabled,
        private FleetServingRelease $serving,
        private FleetConvergeUnits $units,
        private NodeUpdateBroadcaster $nodeUpdates,
    ) {}

    /** @return array{status: string, rollout: ?int, visited: list<array{node: string, outcome: string}>} */
    public function run(): array
    {
        if (! $this->enabled) {
            return $this->summary('disabled');
        }

        $lock = $this->locks->lock(self::LockName, $this->locks->operationSeconds());

        if (! $lock->get()) {
            return $this->summary('busy');
        }

        try {
            return $this->runLocked();
        } finally {
            $lock->release();
        }
    }

    /** @return array{status: string, rollout: ?int, visited: list<array{node: string, outcome: string}>} */
    private function runLocked(): array
    {
        $this->endDeadVisits();
        $halted = FleetRollout::query()->where('status', FleetRolloutStatus::Halted->value)->latest('id')->first();

        if ($halted instanceof FleetRollout) {
            return $this->summary('halted', $halted);
        }

        $state = $this->desired->current();

        if ($state->commit === null) {
            return $this->summary('commit_unknown');
        }

        if ($this->superseded()) {
            return $this->summary('superseded');
        }

        $gate = $this->planner->gate($state->commit);

        if ($gate['state'] === 'unverified' || $gate['state'] === 'unreadable') {
            return $this->summary($gate['state'] === 'unverified' ? 'release_unverified' : 'release_layout_unreadable');
        }

        $this->recheckForeignClis();
        $rollout = FleetRollout::query()->where('commit', $state->commit)->latest('id')->first();
        $open = $rollout instanceof FleetRollout && $rollout->status !== FleetRolloutStatus::Superseded;

        // A started rollout whose CLI release cannot be confirmed now, after a GitHub error for example, visits
        // no Node, because each Node would get a release it cannot install. It keeps the state it rolled out.
        if ($open && $rollout->status !== FleetRolloutStatus::Waiting && ! $state->cli->isAvailable()) {
            return $this->summary('waiting', $rollout);
        }

        $rollout = $open ? $this->planner->refresh($rollout, $state) : $this->planner->open($state, $gate['release']);

        if ($rollout->status === FleetRolloutStatus::Waiting) {
            return $this->wait($rollout, $state);
        }

        $this->noticeCliFallback($rollout, $state);

        $visited = [];
        // A Gateway rollback makes a verified release older than the Nodes' CLI; only that release may downgrade.
        $allowDowngrade = $gate['state'] === 'verified';

        foreach ($this->due($rollout, $state) as $row) {
            $node = $row->node;

            if (! $node instanceof Node || ! $this->membership->includes($node)) {
                continue;
            }

            // A newer release went current while this pass ran: never apply the older desired state.
            if ($this->superseded()) {
                return $this->summary('superseded', $rollout, $visited);
            }

            // A cleared `finished_at` marks the visit in progress, so the Node reads as updating while it runs.
            $row->forceFill(['started_at' => now(), 'finished_at' => null, 'attempts' => $row->attempts + 1])->save();
            $this->nodeUpdates->node($node->id);
            $firstVisit = ! $rollout->nodes()->whereIn('outcome', [FleetNodeOutcome::Converged->value, FleetNodeOutcome::Unchanged->value])->exists();
            $result = $this->visit($row, $node, $state, $allowDowngrade, $firstVisit);
            $previous = $row->evidence ?? [];
            $this->record($row, $result, $previous);
            $this->nodeUpdates->node($node->id);
            $this->watchIncomplete($rollout, $row, $result, $previous);
            $this->noticeCaddySkip($rollout, $row, $result);
            $visited[] = ['node' => $row->node_name, 'outcome' => $result->outcome->value];

            if ($result->outcome === FleetNodeOutcome::Failed) {
                $rollout->forceFill([
                    'status' => FleetRolloutStatus::Halted,
                    'halted_node_id' => $node->id,
                    'error_code' => $result->errorCode,
                    'message' => $result->message,
                ])->save();
                $this->alerts->halted($rollout, $row);

                return $this->summary('halted', $rollout, $visited);
            }
        }

        if ($rollout->status === FleetRolloutStatus::Running) {
            $rollout->forceFill(['status' => FleetRolloutStatus::Completed, 'finished_at' => now()])->save();
        }

        return $this->summary($rollout->status->value, $rollout, $visited);
    }

    /** Converges one Node. A visit that throws still ends, so the Node never reads as updating after it. */
    private function visit(FleetRolloutNode $row, Node $node, DesiredFleetStateData $state, bool $allowDowngrade, bool $firstVisit): FleetNodeResult
    {
        try {
            return $this->converger->converge($node, $state, $allowDowngrade, $firstVisit);
        } catch (Throwable $exception) {
            try {
                $row->forceFill(['finished_at' => now()])->save();
                $this->nodeUpdates->node($node->id);
            } catch (Throwable $cleanup) {
                report($cleanup);
            }

            throw $exception;
        }
    }

    /**
     * Ends the visits a run that died left open. This run holds the fleet lock, so no visit runs: each open visit
     * belongs to a dead process, and its Node is not updating any more.
     */
    private function endDeadVisits(): void
    {
        $open = FleetRolloutNode::query()->whereNotNull('started_at')->whereNull('finished_at')->get();

        foreach ($open as $row) {
            $row->forceFill(['finished_at' => now()])->save();
            $this->nodeUpdates->node($row->node_id);
        }
    }

    /**
     * Whether the Gateway now serves another release directory than the one this process runs from. The pass
     * stops, and a fresh run of the unit starts from the current release shortly after this one exits. It
     * compares directories, not versions, so a leftover `APP_VERSION` never causes a restart loop.
     */
    private function superseded(): bool
    {
        if (! $this->serving->supersedes()) {
            return false;
        }

        $this->units->startLater();

        return true;
    }

    /**
     * While the CLI release is not published, the rollout changes nothing on any Node: the footprint and the
     * CLI reach each Node together, in the sequential visit with its verify and halt, once the release
     * appears. A rollout that waits longer than {@see self::WaitingAlertSeconds} raises one
     * `rollout_stalled` alert: CI never published the release, and no ancestor's release could stand in.
     *
     * @return array{status: string, rollout: ?int, visited: list<array{node: string, outcome: string}>}
     */
    private function wait(FleetRollout $rollout, DesiredFleetStateData $state): array
    {
        if ($rollout->alert === null && $rollout->started_at instanceof Carbon
            && $rollout->started_at->lt(now()->subSeconds(self::WaitingAlertSeconds))) {
            $this->alerts->waiting($rollout, $state);
        }

        return $this->summary('waiting', $rollout);
    }

    /** A fallback CLI release is never silent: the rollout raises `rollout_cli_fallback` once for it. */
    private function noticeCliFallback(FleetRollout $rollout, DesiredFleetStateData $state): void
    {
        if (! $state->cliFallback() || isset(($rollout->notices ?? [])['cli_fallback'])) {
            return;
        }

        $rollout->forceFill(['notices' => [...($rollout->notices ?? []), 'cli_fallback' => $this->alerts->cliFallback($rollout, $state)]])->save();
    }

    /** A skipped Caddyfile is never silent: the first one in a rollout raises `rollout_caddy_skipped` once. */
    private function noticeCaddySkip(FleetRollout $rollout, FleetRolloutNode $row, FleetNodeResult $result): void
    {
        $footprint = $result->evidence['footprint'] ?? null;
        $skipped = is_array($footprint) && is_array($footprint['skipped'] ?? null) ? ($footprint['skipped']['caddy'] ?? null) : null;

        if (! is_array($skipped) || isset(($rollout->notices ?? [])['caddy_skipped'])) {
            return;
        }

        $rollout->forceFill(['notices' => [...($rollout->notices ?? []), 'caddy_skipped' => $this->alerts->caddySkipped($rollout, $row, $skipped)]])->save();
    }

    /** Visits each Node left out for a foreign CLI again, so it rejoins the rollout once an operator moved the file. */
    private function recheckForeignClis(): void
    {
        foreach (NodeFootprintRecord::query()->where('cli_state', NodeCliInstallation::Foreign)->with('node.roles')->get() as $record) {
            $node = $record->node;

            if ($this->converger->recheckCli($node)) {
                FleetRolloutNode::query()
                    ->where('node_id', $node->id)
                    ->where('outcome', FleetNodeOutcome::Skipped->value)
                    ->where('error_code', 'cli.foreign_binary')
                    ->update(['outcome' => FleetNodeOutcome::Pending->value, 'step' => null, 'error_code' => null, 'message' => null]);
            }
        }
    }

    /**
     * The Nodes this run visits, in rollout order.
     *
     * @return list<FleetRolloutNode>
     */
    private function due(FleetRollout $rollout, DesiredFleetStateData $state): array
    {
        $rows = $rollout->nodes()->with('node.roles')->get();
        $positions = array_flip(array_map(static fn (Node $node): int => $node->id, $this->membership->members()));
        $due = [];

        foreach ($rows as $row) {
            if ($row->outcome->awaitsCatchUp() || ($row->outcome->isConverged() && $row->node instanceof Node && $this->lag->drifted($row, $row->node, $state))) {
                $due[] = $row;
            }
        }

        $rank = static fn (FleetRolloutNode $row): array => [
            $row->node_id === null ? PHP_INT_MAX : ($positions[$row->node_id] ?? PHP_INT_MAX),
            $row->position,
        ];
        usort($due, static fn (FleetRolloutNode $left, FleetRolloutNode $right): int => $rank($left) <=> $rank($right));

        return $due;
    }

    /**
     * Counts the visits in a row that `orbit self-update` reported `incomplete`, and raises the stalled alert
     * once when the count reaches the limit. An incomplete Node waits; it never halts the rollout.
     *
     * @param  array<string, mixed>  $previous  The row's evidence before this visit.
     */
    private function watchIncomplete(FleetRollout $rollout, FleetRolloutNode $row, FleetNodeResult $result, array $previous): void
    {
        if ($result->errorCode !== 'fleet.self_update_incomplete') {
            return;
        }

        $visits = (is_int($previous['incomplete_visits'] ?? null) ? $previous['incomplete_visits'] : 0) + 1;
        $evidence = [...($row->evidence ?? []), 'incomplete_visits' => $visits];

        if (isset($previous['stalled_alert'])) {
            $evidence['stalled_alert'] = $previous['stalled_alert'];
        } elseif ($visits >= FleetRolloutAlerts::IncompleteVisits) {
            $evidence['stalled_alert'] = $this->alerts->stalled($rollout, $row, $visits);
        }

        $row->forceFill(['evidence' => $evidence])->save();
    }

    /**
     * @param  array<string, mixed>  $previous  The row's evidence before this visit. The incomplete count and its
     *                                          alert survive every visit that does not converge the Node.
     */
    private function record(FleetRolloutNode $row, FleetNodeResult $result, array $previous = []): void
    {
        $evidence = $result->evidence;

        if (! $result->outcome->isConverged()) {
            foreach (['incomplete_visits', 'stalled_alert'] as $key) {
                if (array_key_exists($key, $previous)) {
                    $evidence[$key] = $previous[$key];
                }
            }
        }

        $row->forceFill([
            'outcome' => $result->outcome,
            'step' => $result->step,
            'error_code' => $result->errorCode,
            'message' => $result->message,
            'evidence' => $evidence === [] ? null : $evidence,
            'cli_version' => $result->cliVersion ?? $row->cli_version,
            'agent_version' => $result->agentVersion ?? $row->agent_version,
            'footprint_digest' => $result->footprintDigest ?? $row->footprint_digest,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @param  list<array{node: string, outcome: string}>  $visited
     * @return array{status: string, rollout: ?int, visited: list<array{node: string, outcome: string}>}
     */
    private function summary(string $status, ?FleetRollout $rollout = null, array $visited = []): array
    {
        return ['status' => $status, 'rollout' => $rollout?->id, 'visited' => $visited];
    }
}
