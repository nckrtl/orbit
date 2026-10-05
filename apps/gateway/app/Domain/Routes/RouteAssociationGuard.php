<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Route;
use App\Models\RouteTarget;

final readonly class RouteAssociationGuard
{
    public function assertTargetAssignable(Route $route, Instance $instance): void
    {
        $association = $this->lockedAssociation($instance, $route->app);

        if (! $association instanceof RouteTarget || $association->route_id === $route->id) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'route.target_conflict',
            message: "Instance [{$instance->id}] is already associated with Route [{$association->route_id}] and cannot be assigned to Route [{$route->id}].",
            status: 409,
        );
    }

    public function assertTargetUnassociated(Instance $instance, ?string $app = null): void
    {
        $association = $this->lockedAssociation($instance, $app);

        if (! $association instanceof RouteTarget) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'route.target_conflict',
            message: "Instance [{$instance->id}] is already associated with Route [{$association->route_id}].",
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
            $instance = Instance::query()
                ->lockForUpdate()
                ->findOrFail($association->instance_id);

            if ($instance->status !== InstanceState::Active) {
                continue;
            }

            throw new ResourceOperationException(
                errorCode: 'route.target_conflict',
                message: "Active Instance [{$instance->id}] must remain associated with Route [{$route->id}].",
                status: 409,
            );
        }
    }

    private function lockedAssociation(Instance $instance, ?string $app): ?RouteTarget
    {
        $app = $instance->appConfiguration($app)['name'];
        $association = RouteTarget::query()
            ->where('app', $app)
            ->where('instance_id', $instance->id)
            ->lockForUpdate()
            ->first();

        return $association instanceof RouteTarget ? $association : null;
    }
}
