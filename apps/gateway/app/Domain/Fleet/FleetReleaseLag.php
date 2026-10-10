<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Data\Fleet\DesiredFleetStateData;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use App\Models\Node;

/**
 * Whether a Node of the rollout set runs behind the desired fleet state: the catch-up's selection and
 * Doctor's `node.release_lag` (ADR 0202). It reads only the Gateway's records and the agent view, never
 * the Node.
 */
final readonly class FleetReleaseLag
{
    public function __construct(
        private DesiredFleetState $desired,
        private FleetRolloutMembership $membership,
        private NodeFootprint $footprint,
        private AgentReportedVersions $versions,
        private bool $enabled,
    ) {}

    /**
     * Whether a converged Node drifted since its visit: its agent, CLI, or footprint differs. Only an available
     * CLI release counts, because a pending version is no release a Node could run.
     */
    public function drifted(FleetRolloutNode $row, Node $node, DesiredFleetStateData $state): bool
    {
        $reported = $this->versions->get($node->id)['version'] ?? null;

        return ($reported !== null && $reported !== $state->agent->version)
            || ($row->cli_version !== null && $state->cli->isAvailable() && $row->cli_version !== $state->cli->version)
            || $this->footprint->drifted($node);
    }

    /**
     * Why the Node lags, or null when it runs the desired state or the check does not apply: the rollout
     * is off, the Node is outside the rollout set, the desired state is not resolved yet, or the Gateway's
     * commit is unknown.
     *
     * @return array{expected: string, observed: string}|null
     */
    public function observe(Node $node): ?array
    {
        if (! $this->enabled || ! $this->membership->includes($node)) {
            return null;
        }

        // Doctor never waits on Git or GitHub: the fleet runner keeps the desired state cached.
        $state = $this->desired->cached();

        if ($state === null || $state->commit === null) {
            return null;
        }

        $expected = substr($state->commit, 0, 12);
        $rollout = FleetRollout::query()
            ->where('commit', $state->commit)
            ->where('status', '!=', FleetRolloutStatus::Superseded->value)
            ->latest('id')
            ->first();

        if (! $rollout instanceof FleetRollout) {
            return ['expected' => $expected, 'observed' => 'no rollout yet'];
        }

        $row = $rollout->nodes()->where('node_id', $node->id)->first();

        if (! $row instanceof FleetRolloutNode) {
            return ['expected' => $expected, 'observed' => 'not in the rollout'];
        }

        if (! $row->outcome->isConverged()) {
            return ['expected' => $expected, 'observed' => $row->outcome->value];
        }

        return $this->drifted($row, $node, $state) ? ['expected' => $expected, 'observed' => 'drifted'] : null;
    }
}
