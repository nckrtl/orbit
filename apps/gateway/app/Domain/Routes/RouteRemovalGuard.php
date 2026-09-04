<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteTarget;

final readonly class RouteRemovalGuard
{
    public function assertAppRemovable(OrbitApp $app): void
    {
        if ($app->routes()->exists()) {
            throw new ResourceOperationException(
                errorCode: 'app.has_routes',
                message: "App [{$app->slug}] still owns Routes.",
                status: 409,
            );
        }
    }

    public function assertClusterRemovable(Cluster $cluster): void
    {
        if ($cluster->routes()->exists()) {
            throw new ResourceOperationException(
                errorCode: 'cluster.has_routes',
                message: "Cluster [{$cluster->name}] still scopes Routes.",
                status: 409,
            );
        }
    }

    public function assertNodeRemovable(Node $node): void
    {
        $referenced =
            $node->scopedRoutes()->exists()
            || $node->generatedRoutes()->exists()
            || RouteTarget::query()
                ->whereHas(
                    'appInstance',
                    static fn ($query) => $query->where('node_id', $node->id),
                )
                ->exists();

        if ($referenced) {
            throw new ResourceOperationException(
                errorCode: 'node.has_routes',
                message: "Node [{$node->name}] is still referenced by Routes.",
                status: 409,
            );
        }
    }

    public function assertRoleRemovable(Node $node, RoleName $role): void
    {
        if (
            ($role === RoleName::AppDev
            || $role === RoleName::AppProd)
            && RouteTarget::query()->whereHas(
                'appInstance',
                static fn ($query) => $query->where('node_id', $node->id),
            )->exists()
        ) {
            throw new NodeRoleValidationException(
                message: "Role [{$role->value}] cannot be removed while node [{$node->name}] hosts Route targets.",
                details: ['reason' => 'route_targets_attached', 'role' => $role->value],
            );
        }
    }

    public function assertRouterRemovable(Cluster $cluster): void
    {
        if (Route::query()->where('cluster_id', $cluster->id)->exists()) {
            throw new ResourceOperationException(
                errorCode: 'cluster.routes_require_router',
                message: "Cluster [{$cluster->name}] still owns Routes that require its Router.",
                status: 409,
            );
        }
    }
}
