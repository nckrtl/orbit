<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeUpdatingData;
use App\Domain\Fleet\FleetRolloutRunner;
use App\Domain\Fleet\FleetRolloutStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Nodes\NodeLocks;
use App\Models\FleetRolloutNode;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Carbon;

/**
 * Which Nodes the Gateway is updating now ([Nodes being updated](/reference/gateway-recovery#nodes-being-updated)):
 *
 * - a Node whose fleet rollout visit started and has not finished, while a rollout run holds the fleet lock;
 * - the Gateway Node while a release record is `running`.
 *
 * It reads every Node's state with two queries, one for the visits in progress and one for a running release, and
 * reads the fleet lock only when a visit is in progress.
 */
final readonly class NodeUpdates
{
    public function __construct(
        private NodeLocks $locks,
    ) {}

    public function forNode(Node $node): ?NodeUpdatingData
    {
        return $this->forNodes([$node])[$node->id] ?? null;
    }

    /**
     * @param  iterable<Node>  $nodes
     * @return array<int, NodeUpdatingData> keyed by Node id, only for the Nodes that are updating
     */
    public function forNodes(iterable $nodes): array
    {
        $ids = [];
        $gateways = [];

        foreach ($nodes as $node) {
            $ids[$node->id] = true;

            if ($this->isGateway($node)) {
                $gateways[] = $node->id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $updates = [];

        foreach ($this->visits() as $visit) {
            if ($visit->node_id !== null && isset($ids[$visit->node_id]) && $visit->started_at instanceof Carbon) {
                $updates[$visit->node_id] = new NodeUpdatingData(
                    kind: NodeUpdateKind::FleetRollout,
                    since: $visit->started_at->toIso8601String(),
                    rollout: $visit->fleet_rollout_id,
                );
            }
        }

        $release = $gateways === [] ? null : $this->runningRelease();

        if ($release instanceof GatewayRelease) {
            foreach ($gateways as $id) {
                $updates[$id] = new NodeUpdatingData(
                    kind: NodeUpdateKind::GatewayRelease,
                    since: ($release->created_at ?? Carbon::now())->toIso8601String(),
                    release: $release->id,
                );
            }
        }

        return $updates;
    }

    /**
     * The visits in progress, oldest first. A catch-up visits Nodes of a completed rollout, so a completed rollout
     * counts as well as a running one. A visit counts only while a run holds the fleet lock: a run that died left
     * its visit open, and the lock runs out within {@see NodeLocks::ConsoleSeconds} once nothing renews it. The next
     * run ends such a visit before it visits any Node.
     *
     * @return iterable<FleetRolloutNode>
     */
    private function visits(): iterable
    {
        $visits = FleetRolloutNode::query()
            ->whereNotNull('started_at')
            ->whereNull('finished_at')
            ->whereHas('rollout', static fn ($query) => $query->whereIn('status', [
                FleetRolloutStatus::Running->value,
                FleetRolloutStatus::Completed->value,
            ]))
            ->orderBy('started_at')
            ->orderBy('id')
            ->get(['id', 'fleet_rollout_id', 'node_id', 'started_at']);

        if ($visits->isEmpty() || ! $this->locks->lock(FleetRolloutRunner::LockName, 1)->isLocked()) {
            return [];
        }

        return $visits;
    }

    private function runningRelease(): ?GatewayRelease
    {
        return GatewayRelease::query()
            ->where('outcome', GatewayRelease::Running)
            ->latest('id')
            ->first(['id', 'created_at']);
    }

    private function isGateway(Node $node): bool
    {
        if ($node->status !== LifecycleStatus::Active) {
            return false;
        }

        return $node->roles->contains(
            static fn (NodeRole $role): bool => $role->role === RoleName::Gateway && $role->status === LifecycleStatus::Active,
        );
    }
}
