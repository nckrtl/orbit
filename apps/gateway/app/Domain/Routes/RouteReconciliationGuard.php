<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Route;

final readonly class RouteReconciliationGuard
{
    public function assertRouteMutable(Route $route): void
    {
        if ($route->status === RouteStatus::Active) {
            $this->refuse();
        }
    }

    public function assertClusterRouterMutable(int $clusterId): void
    {
        if (Route::query()->where('cluster_id', $clusterId)->where('status', RouteStatus::Active)->exists()) {
            $this->refuse();
        }
    }

    public function refuse(): never
    {
        throw new ResourceOperationException(
            errorCode: 'route.reconciliation_required',
            message: 'The active Route cannot change until coordinated reconciliation is available.',
            status: 409,
        );
    }
}
