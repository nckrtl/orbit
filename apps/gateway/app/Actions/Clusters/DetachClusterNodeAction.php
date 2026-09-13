<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\Clusters\ActiveTldScopeGuard;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteMutationReconciler;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class DetachClusterNodeAction
{
    public function __construct(
        private ActiveTldScopeGuard $tldScope,
        private ?RouteMutationReconciler $routes = null,
        private ?RouterLanIngressReconciler $lanIngress = null,
        private ?ClusterRouterDnsSelectionReconciler $dnsSelection = null,
    ) {}

    public function execute(Cluster $cluster, Node $node): Cluster
    {
        $this->lanIngress()->expand(
            nodeOverrides: [$node->id => ['cluster_id' => null]],
            clusterIds: [$cluster->id],
        );

        try {
            $this->dnsSelection()->expand(
                nodeOverrides: [$node->id => ['cluster_id' => null]],
                clusterIds: [$cluster->id],
            );
        } catch (Throwable $exception) {
            $this->lanIngress()->prune(clusterIds: [$cluster->id]);

            throw $exception;
        }

        try {
            /**
             * @var Cluster $updated
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

                $routerAssignments = $lockedNode
                    ->roles()
                    ->where('role', RoleName::Router)
                    ->lockForUpdate()
                    ->get();

                if ($routerAssignments->contains(
                    static fn (NodeRole $assignment): bool => ! $assignment->neverActivated(),
                )) {
                    throw new ResourceOperationException(
                        errorCode: 'cluster.router_detach_forbidden',
                        message: 'Clear the Cluster Router before detaching its Node.',
                        status: 409,
                    );
                }

                foreach ($routerAssignments as $assignment) {
                    $assignment->delete();
                }

                if ($lockedNode->roles()->where('role', RoleName::Ingress)->exists()) {
                    throw new ResourceOperationException(
                        errorCode: 'cluster.ingress_detach_forbidden',
                        message: 'Remove the Cluster Ingress role before detaching its Node.',
                        status: 409,
                    );
                }

                $this->tldScope->assertNodeCanDetach($lockedCluster, $lockedNode);

                ($this->routes ?? app(RouteMutationReconciler::class))->reconcile(nodeOverrides: [
                    $lockedNode->id => ['cluster_id' => null],
                ]);

                $lockedNode->update(['cluster_id' => null]);

                return $lockedCluster->refresh();
            });
        } catch (Throwable $exception) {
            $this->dnsSelection()->prune(clusterIds: [$cluster->id]);
            $this->lanIngress()->prune(clusterIds: [$cluster->id]);

            throw $exception;
        }

        $this->dnsSelection()->prune(
            nodeOverrides: [$node->id => ['cluster_id' => null]],
            clusterIds: [$cluster->id],
        );
        $this->lanIngress()->prune(
            nodeOverrides: [$node->id => ['cluster_id' => null]],
            clusterIds: [$cluster->id],
        );

        return $updated;
    }

    private function lanIngress(): RouterLanIngressReconciler
    {
        return $this->lanIngress ?? app(RouterLanIngressReconciler::class);
    }

    private function dnsSelection(): ClusterRouterDnsSelectionReconciler
    {
        return $this->dnsSelection ?? app(ClusterRouterDnsSelectionReconciler::class);
    }
}
