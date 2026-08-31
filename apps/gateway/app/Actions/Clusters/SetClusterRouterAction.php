<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Facades\DB;

final readonly class SetClusterRouterAction
{
    public function execute(Cluster $cluster, Node $node): Cluster
    {
        /**
         * @var Cluster $updated
         * @mago-expect lint:inline-variable-return The annotation narrows Laravel's transaction result.
         */
        $updated = DB::transaction(function () use ($cluster, $node): Cluster {
            $lockedCluster = Cluster::query()->lockForUpdate()->findOrFail($cluster->id);
            $lockedNode = Node::query()->lockForUpdate()->findOrFail($node->id);

            if (
                $lockedNode->cluster_id !== $lockedCluster->id
                || $lockedNode->status !== LifecycleStatus::Active
            ) {
                throw new ResourceOperationException(
                    errorCode: 'cluster.router_node_invalid',
                    message: 'A Cluster Router must be an active member Node.',
                    status: 409,
                );
            }

            $current = NodeRole::query()
                ->where('cluster_id', $lockedCluster->id)
                ->where('role', RoleName::Router)
                ->lockForUpdate()
                ->first();

            if (
                $current instanceof NodeRole
                && $current->node_id === $lockedNode->id
                && $current->status === LifecycleStatus::Active
            ) {
                return $lockedCluster->refresh();
            }

            $current?->delete();

            NodeRole::query()->create([
                'node_id' => $lockedNode->id,
                'cluster_id' => $lockedCluster->id,
                'role' => RoleName::Router,
                'status' => LifecycleStatus::Active,
            ]);

            return $lockedCluster->refresh();
        });

        return $updated;
    }
}
