<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Data\Fleet\DesiredFleetStateData;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use App\Models\GatewayRelease;
use App\Models\Node;

/**
 * Opens the rollout of a desired fleet state and keeps its Node list current.
 *
 * A Gateway in the release layout rolls out only a commit whose release record is `verified`, so a
 * release that is still running or was switched back never reaches the fleet. An in-place Gateway
 * has no release records, and its running commit is the desired state.
 */
final readonly class FleetRolloutPlanner
{
    public function __construct(
        private FleetRolloutMembership $membership,
        private NodeFootprint $footprint,
        private FleetServingRelease $serving,
    ) {}

    /**
     * Whether the commit may roll out, and the release record it belongs to:
     *
     * - `verified`: the commit has a `verified` release record whose verify step saw the Gateway serve that exact
     *   commit. Adopt's phase 1 records `verified` after a check that the Gateway serves at all, often as `dev`;
     *   that record alone never rolls out.
     * - `in_place`: an in-place Gateway has no release records; its running commit is the desired state.
     * - `unverified`: the Gateway serves from the release layout, and no record of the commit is verified.
     * - `unreadable`: the release layout cannot be read. The rollout fails closed.
     *
     * @return array{state: 'verified'|'in_place'|'unverified'|'unreadable', release: ?GatewayRelease}
     */
    public function gate(string $commit): array
    {
        $release = GatewayRelease::query()
            ->where('sha', $commit)
            ->where('outcome', 'verified')
            ->latest('id')
            ->get()
            ->first(static fn (GatewayRelease $record): bool => self::servedExactly($record, $commit));

        if ($release instanceof GatewayRelease) {
            return ['state' => 'verified', 'release' => $release];
        }

        return ['state' => match ($this->serving->read()['state']) {
            FleetServingRelease::InPlace => 'in_place',
            FleetServingRelease::Adopted => 'unverified',
            default => 'unreadable',
        }, 'release' => null];
    }

    /** Whether the record's verify step saw the Gateway report the commit or its 12-digit release id. */
    private static function servedExactly(GatewayRelease $record, string $commit): bool
    {
        $verify = $record->phases['verify'] ?? null;
        $version = is_array($verify) && is_string($verify['version'] ?? null) ? strtolower(trim($verify['version'])) : '';

        return $version !== '' && ($version === strtolower($commit) || $version === GatewayReleaseCommit::id(strtolower($commit)));
    }

    /**
     * Opens a rollout for the state and supersedes every open rollout of another commit.
     *
     * @param  list<string>  $skipped  Node names that start as `skipped`, carried over from a resumed rollout.
     */
    public function open(DesiredFleetStateData $state, ?GatewayRelease $release, array $skipped = []): FleetRollout
    {
        FleetRollout::query()
            ->whereIn('status', [FleetRolloutStatus::Waiting->value, FleetRolloutStatus::Running->value])
            ->where('commit', '!=', (string) $state->commit)
            ->update(['status' => FleetRolloutStatus::Superseded->value, 'finished_at' => now()]);

        $members = $this->membership->members();
        $rollout = FleetRollout::query()->create([
            'gateway_release_id' => $release?->id,
            'commit' => (string) $state->commit,
            'status' => $state->cli->isAvailable() ? FleetRolloutStatus::Running : FleetRolloutStatus::Waiting,
            'desired_state' => $this->stored($state, $members),
            'order' => array_map(static fn (Node $node): int => $node->id, $members),
            'started_at' => now(),
        ]);

        foreach ($members as $position => $node) {
            FleetRolloutNode::query()->create([
                'fleet_rollout_id' => $rollout->id,
                'node_id' => $node->id,
                'node_name' => $node->name,
                'position' => $position + 1,
                'outcome' => in_array($node->name, $skipped, true) ? FleetNodeOutcome::Skipped : FleetNodeOutcome::Pending,
            ]);
        }

        return $rollout;
    }

    /**
     * Stores the newest resolution of the state on a rollout, and adds a Node that joined the rollout
     * set since, at the end. A Waiting rollout starts once the CLI release is published.
     */
    public function refresh(FleetRollout $rollout, DesiredFleetStateData $state): FleetRollout
    {
        $members = $this->membership->members();
        $known = $rollout->nodes()->pluck('node_id')->all();
        $highest = $rollout->nodes()->max('position');
        $position = is_numeric($highest) ? (int) $highest : 0;

        foreach ($members as $node) {
            if (in_array($node->id, $known, true)) {
                continue;
            }

            FleetRolloutNode::query()->create([
                'fleet_rollout_id' => $rollout->id,
                'node_id' => $node->id,
                'node_name' => $node->name,
                'position' => ++$position,
                'outcome' => FleetNodeOutcome::Pending,
            ]);
        }

        $attributes = ['desired_state' => $this->stored($state, $members)];

        if ($rollout->status === FleetRolloutStatus::Waiting && $state->cli->isAvailable()) {
            $attributes['status'] = FleetRolloutStatus::Running;
        }

        $rollout->forceFill($attributes)->save();

        return $rollout->refresh();
    }

    /**
     * The desired state as the release record keeps it: the commit, the CLI release, the agent pin,
     * and the expected footprint digest of each Node in the rollout set.
     *
     * @param  list<Node>  $members
     * @return array<string, mixed>
     */
    private function stored(DesiredFleetStateData $state, array $members): array
    {
        $footprints = [];

        foreach ($members as $node) {
            $footprints[$node->name] = $this->footprint->expected($node)->digest();
        }

        return [...$state->toArray(), 'footprints' => $footprints];
    }
}
