<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Route;
use App\Models\RouteTarget;

final readonly class RouteAssociationGuard
{
    public function assertTargetAssignable(Route $route, AppInstance $appInstance): void
    {
        $association = RouteTarget::query()
            ->where('app_instance_id', $appInstance->id)
            ->lockForUpdate()
            ->first();

        if (! $association instanceof RouteTarget || $association->route_id === $route->id) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'route.target_conflict',
            message: "AppInstance [{$appInstance->id}] is already associated with Route [{$association->route_id}] and cannot be assigned to Route [{$route->id}].",
            status: 409,
        );
    }

    public function assertTargetsDetachable(Route $route): void
    {
        $associations = RouteTarget::query()
            ->where('route_id', $route->id)
            ->orderBy('position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($associations as $association) {
            $appInstance = AppInstance::query()
                ->lockForUpdate()
                ->findOrFail($association->app_instance_id);

            if ($appInstance->status !== AppInstanceState::Active) {
                continue;
            }

            throw new ResourceOperationException(
                errorCode: 'route.target_conflict',
                message: "Active AppInstance [{$appInstance->id}] must remain associated with Route [{$route->id}].",
                status: 409,
            );
        }
    }
}
