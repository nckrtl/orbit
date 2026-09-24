<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Actions\Routes\ConvergeRouteAction;
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
        private ?ConvergeRouteAction $convergeRoute = null,
        private ?RouterLanIngressReconciler $lanIngress = null,
        private ?ClusterRouterDnsSelectionReconciler $dnsSelection = null,
    ) {}

    public function execute(Cluster $cluster, Node $node): Cluster
    {
        $node->refresh();
        $cluster->refresh();

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
            $this->assertDetachable($cluster, $node);
            $this->convergeMembership([$node->id => ['cluster_id' => null]]);

            /**
             * @var Cluster $updated
             */
            $updated = DB::transaction(function () use ($cluster, $node): Cluster {
                $lockedCluster = Cluster::query()->lockForUpdate()->findOrFail($cluster->id);
                $lockedNode = Node::query()->lockForUpdate()->findOrFail($node->id);

                $this->assertDetachable($lockedCluster, $lockedNode);

                $routerAssignments = $lockedNode
                    ->roles()
                    ->where('role', RoleName::Router)
                    ->lockForUpdate()
                    ->get();

                foreach ($routerAssignments as $assignment) {
                    $assignment->delete();
                }

                $this->routeReconciler()->reconcile(nodeOverrides: [
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

    private function assertDetachable(Cluster $cluster, Node $node): void
    {
        if ($node->cluster_id !== $cluster->id) {
            throw new ResourceOperationException(
                errorCode: 'cluster.membership_missing',
                message: "Node [{$node->name}] does not belong to Cluster [{$cluster->name}].",
                status: 409,
            );
        }

        $routerAssignments = $node
            ->roles()
            ->where('role', RoleName::Router)
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

        if ($node->roles()->where('role', RoleName::Ingress)->exists()) {
            throw new ResourceOperationException(
                errorCode: 'cluster.ingress_detach_forbidden',
                message: 'Remove the Cluster Ingress role before detaching its Node.',
                status: 409,
            );
        }

        $this->tldScope->assertNodeCanDetach($cluster, $node);
    }

    /** @param array<int, array{cluster_id: ?int}> $overrides */
    private function convergeMembership(array $overrides): void
    {
        $converged = [];

        foreach ($this->routeReconciler()->membershipChanges(nodeOverrides: $overrides) as $change) {
            $converged[] = $this->convergeRoute()->execute(
                $change['route'],
                $change['domain'],
                allowGenerated: true,
                placement: $change['placement'],
                deferPlacementWithdrawal: true,
            );
        }

        // One wait for private DNS answers to expire covers every Route this change moved.
        $this->convergeRoute()->completePlacements($converged);
    }

    private function routeReconciler(): RouteMutationReconciler
    {
        return $this->routes ?? app(RouteMutationReconciler::class);
    }

    private function convergeRoute(): ConvergeRouteAction
    {
        return $this->convergeRoute ?? app(ConvergeRouteAction::class);
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
