<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

final readonly class DetachClusterNodeAction
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

            if ($lockedNode->cluster_id !== $lockedCluster->id) {
                throw new ResourceOperationException(
                    errorCode: 'cluster.membership_missing',
                    message: "Node [{$lockedNode->name}] does not belong to Cluster [{$lockedCluster->name}].",
                    status: 409,
                );
            }

            if ($lockedNode->appInstances()->exists()) {
                throw new ResourceOperationException(
                    errorCode: 'cluster.node_has_app_instances',
                    message: "Node [{$lockedNode->name}] still owns AppInstances.",
                    status: 409,
                );
            }

            if ($lockedNode->roles()->where('role', RoleName::Router)->exists()) {
                throw new ResourceOperationException(
                    errorCode: 'cluster.router_detach_forbidden',
                    message: 'Clear the Cluster Router before detaching its Node.',
                    status: 409,
                );
            }

            if ($lockedNode->roles()->where('role', RoleName::Ingress)->exists()) {
                throw new ResourceOperationException(
                    errorCode: 'cluster.ingress_detach_forbidden',
                    message: 'Remove the Cluster Ingress role before detaching its Node.',
                    status: 409,
                );
            }

            $lockedNode->update(['cluster_id' => null]);

            return $lockedCluster->refresh();
        });

        return $updated;
    }
}
