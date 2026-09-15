<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Data\Routes\RouteTargetDispositionData;
use App\Data\Routes\SetRouteTargetsData;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Route;
use App\Models\RouteTarget;
use Illuminate\Support\Collection;

final readonly class RouteTargetSetGuard
{
    public function assertMutable(Route $route): void
    {
        if ($route->provenance !== RouteProvenance::Explicit) {
            $this->refuse('route.pool_unsupported', 'A generated Route cannot own a production target pool.');
        }

        if ($route->cluster_id === null) {
            $this->refuse('route.pool_unsupported', 'A Node-scoped Route cannot own a production target pool.');
        }
    }

    public function assertProposal(Route $route, SetRouteTargetsData $proposal): void
    {
        $this->assertMutable($route);
        $this->assertNoUnsupportedFields($proposal);
        $this->assertUniqueTargets($proposal);
        $this->assertDispositionsCoverDetached($route, $proposal);

        $retained = $this->instances($proposal->targetIds);
        $this->assertAssignableTargets($route, $retained);

        foreach ($proposal->dispositions as $disposition) {
            $this->assertDisposition($route, $disposition, $proposal);
        }
    }

    public function assertCompatibleIntent(Route $route, SetRouteTargetsData $proposal): void
    {
        $current = $route->target_set_intent;

        if (! is_array($current)) {
            return;
        }

        unset($current['vacated_route_ids']);

        if ($current !== $proposal->toIntent()) {
            $this->refuse(
                'route.target_set_conflict',
                'The Route already has another target-set change in progress.',
            );
        }
    }

    /** @return Collection<int, AppInstance> */
    public function instances(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $instances = AppInstance::query()
            ->with(['app', 'node.roles', 'node.cluster'])
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            if (! $instances->has($id)) {
                $this->refuse('route.target_inactive', 'The Route target must be active.');
            }
        }

        return $instances;
    }

    private function assertNoUnsupportedFields(SetRouteTargetsData $proposal): void
    {
        foreach ($proposal->dispositions as $disposition) {
            if ($disposition->routeId !== null && $disposition->remove) {
                $this->refuse(
                    'route.target_disposition_invalid',
                    'A detached App instance must be reassigned or removed, not both.',
                );
            }
        }
    }

    private function assertUniqueTargets(SetRouteTargetsData $proposal): void
    {
        if (count($proposal->targetIds) !== count(array_unique($proposal->targetIds))) {
            $this->refuse('route.target_conflict', 'A Route target set cannot contain a duplicate App instance.');
        }

        $dispositionIds = array_map(
            static fn (RouteTargetDispositionData $disposition): int => $disposition->appInstanceId,
            $proposal->dispositions,
        );

        if (count($dispositionIds) !== count(array_unique($dispositionIds))) {
            $this->refuse('route.target_conflict', 'A Route target set cannot contain a duplicate App instance.');
        }

        if (array_intersect($proposal->targetIds, $dispositionIds) !== []) {
            $this->refuse(
                'route.target_disposition_invalid',
                'A retained App instance cannot also have a detach disposition.',
            );
        }
    }

    private function assertDispositionsCoverDetached(Route $route, SetRouteTargetsData $proposal): void
    {
        $current = $route
            ->targets()
            ->orderBy('position')
            ->pluck('app_instance_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $detached = array_values(array_diff($current, $proposal->targetIds));
        $accounted = array_map(
            static fn (RouteTargetDispositionData $disposition): int => $disposition->appInstanceId,
            $proposal->dispositions,
        );

        sort($detached);
        sort($accounted);

        if ($detached !== $accounted) {
            $this->refuse(
                'route.target_disposition_required',
                'A target-set change must account for every detached active App instance.',
            );
        }
    }

    /** @param Collection<int, AppInstance> $instances */
    private function assertAssignableTargets(Route $route, Collection $instances): void
    {
        $nodeIds = [];

        foreach ($instances as $instance) {
            if ($instance->app_id !== $route->app_id) {
                $this->refuse('route.target_app_conflict', 'The Route target must belong to the Route App.');
            }

            if ($instance->status !== AppInstanceState::Active) {
                $this->refuse('route.target_inactive', 'The Route target must be active.');
            }

            if ($instance->environment !== 'production') {
                $this->refuse('route.pool_unsupported', 'A production Route pool cannot include an app-dev target.');
            }

            $node = $instance->node;

            if (
                $node->status !== LifecycleStatus::Active
                || $node->cluster_id !== $route->cluster_id
            ) {
                $this->refuse(
                    'route.target_scope_conflict',
                    'A production Route pool target must use an active app-prod Node in the Route Cluster.',
                );
            }

            $hasAppProd = $node->roles->contains(
                static fn ($role): bool => $role->role === RoleName::AppProd
                    && $role->status === LifecycleStatus::Active,
            );

            if (! $hasAppProd) {
                $this->refuse(
                    'route.target_scope_conflict',
                    'A production Route pool target must use an active app-prod Node in the Route Cluster.',
                );
            }

            if (in_array($node->id, $nodeIds, true)) {
                $this->refuse(
                    'route.target_conflict',
                    'A production Route pool cannot place two targets on one Node.',
                );
            }

            $nodeIds[] = $node->id;

            $association = RouteTarget::query()
                ->where('app_instance_id', $instance->id)
                ->lockForUpdate()
                ->first();

            if ($association instanceof RouteTarget && $association->route_id !== $route->id) {
                continue;
            }
        }
    }

    private function assertDisposition(
        Route $route,
        RouteTargetDispositionData $disposition,
        SetRouteTargetsData $proposal,
    ): void {
        $instance = $this->instances([$disposition->appInstanceId])->first();

        if (! $instance instanceof AppInstance) {
            $this->refuse('route.target_inactive', 'The Route target must be active.');
        }

        if ($instance->status === AppInstanceState::Active && ! $disposition->remove && $disposition->routeId === null) {
            $this->refuse(
                'route.target_disposition_required',
                'A detached active App instance must be reassigned or authorized for removal.',
            );
        }

        if ($disposition->remove) {
            return;
        }

        $destination = Route::query()->lockForUpdate()->find($disposition->routeId);

        if (! $destination instanceof Route) {
            $this->refuse('route.target_disposition_invalid', 'The reassignment destination Route does not exist.');
        }

        if ($destination->id === $route->id) {
            $this->refuse('route.target_disposition_invalid', 'A detached App instance cannot be reassigned to the same Route.');
        }

        if ($destination->app_id !== $route->app_id) {
            $this->refuse('route.target_app_conflict', 'The Route target must belong to the Route App.');
        }

        if ($destination->provenance !== RouteProvenance::Explicit) {
            $this->refuse('route.pool_unsupported', 'A generated Route cannot receive a reassigned production target.');
        }

        if ($destination->cluster_id !== $instance->node->cluster_id) {
            $this->refuse(
                'route.target_scope_conflict',
                'A reassignment destination must stay in the App instance Cluster.',
            );
        }

        $existing = $destination
            ->targets()
            ->pluck('app_instance_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if (in_array($instance->id, $existing, true)) {
            return;
        }

        $destinationNodeIds = AppInstance::query()
            ->whereIn('id', $existing)
            ->pluck('node_id')
            ->all();

        if (in_array($instance->node_id, $destinationNodeIds, true)) {
            $this->refuse(
                'route.target_conflict',
                'A production Route pool cannot place two targets on one Node.',
            );
        }
    }

    private function refuse(string $errorCode, string $message): never
    {
        throw new ResourceOperationException(
            errorCode: $errorCode,
            message: $message,
            status: 409,
        );
    }
}
