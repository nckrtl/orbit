<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\NodeRole;
use Illuminate\Support\Facades\DB;

final readonly class ClearClusterRouterAction
{
    public function execute(Cluster $cluster): Cluster
    {
        /**
         * @var Cluster $updated
         * @mago-expect lint:inline-variable-return The annotation narrows Laravel's transaction result.
         */
        $updated = DB::transaction(function () use ($cluster): Cluster {
            $lockedCluster = Cluster::query()->lockForUpdate()->findOrFail($cluster->id);

            if ($lockedCluster->state === ClusterState::Active && $lockedCluster->tld !== null) {
                throw new ResourceOperationException(
                    errorCode: 'cluster.active_router_required',
                    message: 'Deactivate the TLD-bearing Cluster or remove its TLD before clearing its Router.',
                    status: 409,
                );
            }

            NodeRole::query()
                ->where('cluster_id', $lockedCluster->id)
                ->where('role', RoleName::Router)
                ->delete();

            return $lockedCluster->refresh();
        });

        return $updated;
    }
}
