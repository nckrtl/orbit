<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;

final readonly class PublicRouteEligibility
{
    public function canActivate(Route $route): bool
    {
        return $this->reason($route) === null;
    }

    public function reason(Route $route): ?string
    {
        if ($route->publication !== RoutePublication::Public) {
            return 'publication-private';
        }

        if ($route->node_id !== null || $route->cluster_id === null) {
            return 'node-scope';
        }

        $cluster = $route->relationLoaded('cluster')
            ? $route->cluster
            : Cluster::query()->find($route->cluster_id);

        if (! $cluster instanceof Cluster || $cluster->state !== ClusterState::Active) {
            return 'cluster-inactive';
        }

        if (! $this->activeRouter($cluster) instanceof Node) {
            return 'router-missing';
        }

        if (! $this->activeIngress($cluster) instanceof Node) {
            return 'ingress-missing';
        }

        return null;
    }

    public function activeIngress(Cluster $cluster): ?Node
    {
        $assignment = NodeRole::query()
            ->with('node')
            ->where('cluster_id', $cluster->id)
            ->where('role', RoleName::Ingress)
            ->where('status', LifecycleStatus::Active)
            ->first();

        $node = $assignment?->node;

        return $node instanceof Node && $node->status === LifecycleStatus::Active
            ? $node
            : null;
    }

    public function activeRouter(Cluster $cluster): ?Node
    {
        $cluster->loadMissing('routerAssignment.node');
        $node = $cluster->routerAssignment?->node;

        return $node instanceof Node && $node->status === LifecycleStatus::Active
            ? $node
            : null;
    }

    public function ingressDependsOnPublicRoutes(Node $node): bool
    {
        $assignment = NodeRole::query()
            ->where('node_id', $node->id)
            ->where('role', RoleName::Ingress)
            ->first();

        if (! $assignment instanceof NodeRole || $assignment->cluster_id === null) {
            return false;
        }

        return Route::query()
            ->where('cluster_id', $assignment->cluster_id)
            ->where('publication', RoutePublication::Public)
            ->exists();
    }

    public function clusterHasActivePublicRoute(int $clusterId, ?int $exceptRouteId = null): bool
    {
        return Route::query()
            ->where('cluster_id', $clusterId)
            ->where('publication', RoutePublication::Public)
            ->where('public_publication', RoutePublicPublication::Active)
            ->whereIn('status', [RouteStatus::Active, RouteStatus::Activating])
            ->when(
                $exceptRouteId !== null,
                static fn ($query) => $query->whereKeyNot($exceptRouteId),
            )
            ->exists();
    }
}
