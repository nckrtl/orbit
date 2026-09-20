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

    /**
     * A public edge is live when publication is public, the Route is authoritative, the Cluster
     * can serve Ingress, and activation has reached the public handler or has finished.
     */
    public function publicEdgeIsLive(Route $route): bool
    {
        if (! in_array($route->status, [RouteStatus::Active, RouteStatus::Activating], true)) {
            return false;
        }

        if (! $this->canActivate($route)) {
            return false;
        }

        return $this->publicActivationReached($route->replacement_step);
    }

    public function publicActivationReached(?RouteReplacementStep $step): bool
    {
        return $step === null
            || $this->publicActivationRank($step) >= $this->publicActivationRank(RouteReplacementStep::PublicActivated);
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
            ->with(['cluster.routerAssignment.node', 'cluster.ingressAssignment.node'])
            ->where('cluster_id', $clusterId)
            ->where('publication', RoutePublication::Public)
            ->whereIn('status', [RouteStatus::Active, RouteStatus::Activating])
            ->when(
                $exceptRouteId !== null,
                static fn ($query) => $query->whereKeyNot($exceptRouteId),
            )
            ->get()
            ->contains(fn (Route $route): bool => $this->publicEdgeIsLive($route));
    }

    public function publicActivationRank(?RouteReplacementStep $step): int
    {
        return match ($step) {
            RouteReplacementStep::IngressCertificate => 1,
            RouteReplacementStep::IngressCaddy => 2,
            RouteReplacementStep::PublicEdgeVerified => 3,
            RouteReplacementStep::PublicActivated => 4,
            RouteReplacementStep::IngressFirewall => 5,
            RouteReplacementStep::Cleanup => 6,
            default => 0,
        };
    }
}
