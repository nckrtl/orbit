<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Domain\Routes\RouteMutationReconciler;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class AttachClusterNodeAction
{
    public function __construct(
        private ?RouteMutationReconciler $routes = null,
    ) {}

    public function execute(Cluster $cluster, Node $node): Cluster
    {
        /**
         * @var Cluster $updated
         * @mago-expect lint:inline-variable-return The annotation narrows Laravel's transaction result.
         */
        $updated = DB::transaction(function () use ($cluster, $node): Cluster {
            $lockedCluster = Cluster::query()->lockForUpdate()->findOrFail($cluster->id);
            $lockedNode = Node::query()->lockForUpdate()->findOrFail($node->id);

            if ($lockedNode->status !== LifecycleStatus::Active) {
                throw new ResourceOperationException(
                    errorCode: 'cluster.node_inactive',
                    message: "Node [{$lockedNode->name}] must be active before Cluster attachment.",
                    status: 409,
                );
            }

            if ($lockedNode->cluster_id !== null && $lockedNode->cluster_id !== $lockedCluster->id) {
                throw new ResourceOperationException(
                    errorCode: 'cluster.membership_conflict',
                    message: "Node [{$lockedNode->name}] already belongs to another Cluster.",
                    status: 409,
                );
            }

            try {
                ($this->routes ?? app(RouteMutationReconciler::class))->reconcile(nodeOverrides: [
                    $lockedNode->id => ['cluster_id' => $lockedCluster->id],
                ]);
                $lockedNode->update(['cluster_id' => $lockedCluster->id]);
            } catch (QueryException $exception) {
                throw new ResourceOperationException(
                    errorCode: 'cluster.lan_ip_conflict',
                    message: "Node [{$lockedNode->name}] conflicts with a Cluster LAN address.",
                    status: 409,
                    previous: $exception,
                );
            }

            return $lockedCluster->refresh();
        });

        return $updated;
    }
}
