<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Route;
use App\Models\RouteTarget;

final readonly class RouteAssociationGuard
{
    public function assertTargetAssignable(Route $route, Instance $appInstance): void
    {
        $association = $this->lockedAssociation($appInstance);

        if (! $association instanceof RouteTarget || $association->route_id === $route->id) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'route.target_conflict',
            message: "Instance [{$appInstance->id}] is already associated with Route [{$association->route_id}] and cannot be assigned to Route [{$route->id}].",
            status: 409,
        );
    }

    public function assertTargetUnassociated(Instance $appInstance): void
    {
        $association = $this->lockedAssociation($appInstance);

        if (! $association instanceof RouteTarget) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'route.target_conflict',
            message: "Instance [{$appInstance->id}] is already associated with Route [{$association->route_id}].",
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
            $appInstance = Instance::query()
                ->lockForUpdate()
                ->findOrFail($association->instance_id);

            if ($appInstance->status !== AppInstanceState::Active) {
                continue;
            }

            throw new ResourceOperationException(
                errorCode: 'route.target_conflict',
                message: "Active Instance [{$appInstance->id}] must remain associated with Route [{$route->id}].",
                status: 409,
            );
        }
    }

    private function lockedAssociation(Instance $appInstance): ?RouteTarget
    {
        $association = RouteTarget::query()
            ->where('instance_id', $appInstance->id)
            ->lockForUpdate()
            ->first();

        return $association instanceof RouteTarget ? $association : null;
    }
}
