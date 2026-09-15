<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Actions\AppInstances\RemoveAppInstanceAction;
use App\Data\Routes\RouteTargetDispositionData;
use App\Data\Routes\SetRouteTargetsData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRouteDomain;
use App\Domain\AppInstances\Environment\AppInstanceRouteEnvironmentSynchronizer;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteTargetSetGuard;
use App\Domain\Routes\RouteTargetSetStep;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Route;
use App\Models\RouteTarget;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ConvergeRouteTargetSetAction
{
    public function __construct(
        private RouteTargetSetGuard $guard,
        private RouteDomainProjector $projection,
        private AppInstanceRouteEnvironmentSynchronizer $routeEnvironment,
        private AppInstanceEnvironmentOperationLock $environmentOperations,
        private DevelopmentProjectionOperationLock $owner,
        private RemoveAppInstanceAction $removals,
    ) {}

    public function execute(Route $route, SetRouteTargetsData $proposal): Route
    {
        $ownerIds = $this->ownerIds($route, $proposal);

        return $this->environmentOperations->run(
            $ownerIds,
            fn (): Route => $this->owner->run(
                fn (): Route => $this->convergeOwned($route->id, $proposal, $ownerIds),
            ),
        );
    }

    /** @param list<int> $expectedOwnerIds */
    private function convergeOwned(int $routeId, SetRouteTargetsData $proposal, array $expectedOwnerIds): Route
    {
        $route = Route::query()
            ->with(['targets.appInstance.app', 'targets.appInstance.node', 'cluster.routerAssignment.node'])
            ->findOrFail($routeId);

        if ($this->ownerIds($route, $proposal) !== $expectedOwnerIds) {
            throw new ResourceOperationException(
                errorCode: 'env.owner_changed',
                message: 'The AppInstance environment owner changed during the operation.',
                status: 409,
            );
        }

        $this->guard->assertCompatibleIntent($route, $proposal);

        if ($this->isCompletedNoop($route, $proposal)) {
            return $route;
        }

        $step = RouteTargetSetStep::tryFrom((string) $route->target_set_step) ?? RouteTargetSetStep::Reserved;

        if ($this->rank($step) < $this->rank(RouteTargetSetStep::DatabaseCommitted)) {
            $this->guard->assertProposal($route, $proposal);
        }

        $this->reserve($route, $proposal);
        $step = RouteTargetSetStep::tryFrom((string) $route->target_set_step) ?? RouteTargetSetStep::Reserved;
        $failureStep = $step->value;

        try {
            if ($this->rank($step) < $this->rank(RouteTargetSetStep::WorkloadPrepared)) {
                $failureStep = 'workload-prepared';
                $this->prepareWorkloads($route, $proposal);
                $this->advance($route, RouteTargetSetStep::WorkloadPrepared);
            }

            if ($this->rank($step) < $this->rank(RouteTargetSetStep::DatabaseCommitted)) {
                $failureStep = 'database-committed';
                $vacatedRouteIds = $this->vacatedRouteIds($route, $proposal);
                $this->commitAssociations($route, $proposal);
                $route = $route->refresh()->load(['targets.appInstance.app', 'targets.appInstance.node']);
                $this->advance($route, RouteTargetSetStep::DatabaseCommitted);
                $step = RouteTargetSetStep::DatabaseCommitted;
                $route->update([
                    'target_set_intent' => [
                        ...$proposal->toIntent(),
                        'vacated_route_ids' => $vacatedRouteIds,
                    ],
                ]);
            }

            if ($this->rank($step) < $this->rank(RouteTargetSetStep::EnvironmentSynchronized)) {
                $failureStep = 'environment-synchronized';
                $this->synchronizeEnvironments($route, $proposal);
                $this->advance($route, RouteTargetSetStep::EnvironmentSynchronized);
            }

            if ($this->rank($step) < $this->rank(RouteTargetSetStep::RouterPublished)) {
                $failureStep = 'router-published';
                $this->publishRoute($route, $proposal);
                $this->advance($route, RouteTargetSetStep::RouterPublished);
            }

            if ($this->rank($step) < $this->rank(RouteTargetSetStep::VacatedPublished)) {
                $failureStep = 'vacated-published';
                $this->publishVacated($route, $proposal);
                $this->advance($route, RouteTargetSetStep::VacatedPublished);
            }

            if ($this->rank($step) < $this->rank(RouteTargetSetStep::Removal)) {
                $failureStep = 'removal';
                $this->removeAuthorized($proposal);
                $this->advance($route, RouteTargetSetStep::Removal);
            }

            $this->complete($route);
        } catch (Throwable $exception) {
            $this->recordFailure($route, $failureStep, $this->errorCode($exception));

            if ($this->rank(RouteTargetSetStep::tryFrom($failureStep) ?? RouteTargetSetStep::Reserved) < $this->rank(RouteTargetSetStep::DatabaseCommitted)) {
                $this->rollbackPreparations($route, $proposal);
            }

            throw $exception;
        }

        return $route->refresh()->load('targets');
    }

    private function isCompletedNoop(Route $route, SetRouteTargetsData $proposal): bool
    {
        if ($route->target_set_intent !== null) {
            return false;
        }

        $current = $route
            ->targets()
            ->orderBy('position')
            ->pluck('app_instance_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $current === $proposal->targetIds;
    }

    private function reserve(Route $route, SetRouteTargetsData $proposal): void
    {
        if (is_array($route->target_set_intent)) {
            return;
        }

        $route->update([
            'target_set_intent' => $proposal->toIntent(),
            'target_set_step' => RouteTargetSetStep::Reserved->value,
            'failed_step' => null,
            'error_code' => null,
        ]);
        $route->refresh();
    }

    private function prepareWorkloads(Route $route, SetRouteTargetsData $proposal): void
    {
        foreach ($this->preparedInstances($route, $proposal) as [$instance, $destination]) {
            $this->projection->prepareWorkloadCertificate($instance, $destination, $destination);
            $this->projection->prepareWorkloadCaddy($instance, $destination, $destination);
            $this->projection->prepareRouterCertificate($instance, $destination, $destination);
            $this->projection->prepareFirewallPolicy($instance, $destination);
            $this->projection->verifyWorkload($instance, $destination);
        }
    }

    private function commitAssociations(Route $route, SetRouteTargetsData $proposal): void
    {
        DB::transaction(function () use ($route, $proposal): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $this->guard->assertProposal($locked, $proposal);
            $vacatedRouteIds = $this->vacatedRouteIds($locked, $proposal);
            $removeIds = [];
            $reassignments = [];

            foreach ($proposal->dispositions as $disposition) {
                if ($disposition->remove) {
                    $removeIds[] = $disposition->appInstanceId;
                } elseif ($disposition->routeId !== null) {
                    $reassignments[$disposition->appInstanceId] = $disposition->routeId;
                }
            }

            foreach ($proposal->targetIds as $appInstanceId) {
                $this->associateOnRoute($locked, $appInstanceId);
            }

            foreach ($reassignments as $appInstanceId => $destinationId) {
                $this->reassignToRoute($appInstanceId, $destinationId);
            }

            $this->compactPositions($locked->id, [...$proposal->targetIds, ...$removeIds]);

            foreach ($vacatedRouteIds as $vacatedRouteId) {
                $this->compactPositions($vacatedRouteId);
            }
        });
    }

    private function associateOnRoute(Route $route, int $appInstanceId): void
    {
        $existing = RouteTarget::query()
            ->where('app_instance_id', $appInstanceId)
            ->lockForUpdate()
            ->first();

        if ($existing instanceof RouteTarget && $existing->route_id === $route->id) {
            return;
        }

        $position = RouteTarget::query()->where('route_id', $route->id)->count();

        if ($existing instanceof RouteTarget) {
            $existing->update([
                'route_id' => $route->id,
                'position' => $position,
            ]);

            return;
        }

        RouteTarget::query()->create([
            'route_id' => $route->id,
            'app_instance_id' => $appInstanceId,
            'position' => $position,
        ]);
    }

    private function reassignToRoute(int $appInstanceId, int $destinationId): void
    {
        $existing = RouteTarget::query()
            ->where('app_instance_id', $appInstanceId)
            ->lockForUpdate()
            ->first();

        if (! $existing instanceof RouteTarget || $existing->route_id === $destinationId) {
            return;
        }

        $destination = Route::query()->lockForUpdate()->findOrFail($destinationId);
        $existing->update([
            'route_id' => $destination->id,
            'position' => RouteTarget::query()->where('route_id', $destination->id)->count(),
        ]);

        if ($destination->status === RouteStatus::Pending) {
            $destination->update(['status' => RouteStatus::Active]);
        }
    }

    /** @param list<int>|null $orderedInstanceIds */
    private function compactPositions(int $routeId, ?array $orderedInstanceIds = null): void
    {
        if ($orderedInstanceIds !== null) {
            $rows = RouteTarget::query()
                ->where('route_id', $routeId)
                ->lockForUpdate()
                ->get()
                ->keyBy('app_instance_id');
            $position = 0;

            foreach ($orderedInstanceIds as $appInstanceId) {
                $row = $rows->get($appInstanceId);

                if (! $row instanceof RouteTarget) {
                    continue;
                }

                if ($row->position !== $position) {
                    $row->update(['position' => $position]);
                }

                $position++;
            }

            return;
        }

        $rows = RouteTarget::query()
            ->where('route_id', $routeId)
            ->lockForUpdate()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->values();

        foreach ($rows as $position => $row) {
            if ($row->position !== $position) {
                $row->update(['position' => $position]);
            }
        }
    }

    private function synchronizeEnvironments(Route $route, SetRouteTargetsData $proposal): void
    {
        foreach ($this->synchronizedInstances($route, $proposal) as $instance) {
            $this->routeEnvironment->synchronizeRouteDomain(
                $instance,
                AppInstanceEnvironmentRouteDomain::Authoritative,
            );
        }
    }

    private function publishRoute(Route $route, SetRouteTargetsData $proposal): void
    {
        $instances = $this->guard->instances($proposal->targetIds);

        if ($instances->isEmpty()) {
            $probeId = $proposal->dispositions[0]->appInstanceId ?? null;
            $probe = is_int($probeId) ? AppInstance::query()->find($probeId) : null;

            if ($probe instanceof AppInstance) {
                $this->projection->prepareRouterCaddy($probe, $route, $route);
            }

            return;
        }

        foreach ($instances as $instance) {
            $this->projection->prepareRouterCaddy($instance, $route, $route);
        }
    }

    private function publishVacated(Route $route, SetRouteTargetsData $proposal): void
    {
        $ids = $route->target_set_intent['vacated_route_ids'] ?? $this->vacatedRouteIds($route, $proposal);

        foreach ($proposal->dispositions as $disposition) {
            if (! $disposition->remove && $disposition->routeId !== null) {
                $ids[] = $disposition->routeId;
            }
        }

        $published = [];

        foreach (array_values(array_unique($ids)) as $routeId) {
            if ($routeId === $route->id || isset($published[$routeId])) {
                continue;
            }

            $destination = Route::query()->with(['targets.appInstance'])->find($routeId);

            if (! $destination instanceof Route) {
                continue;
            }

            $published[$destination->id] = true;
            $target = $destination->targets->first()?->appInstance
                ?? AppInstance::query()->find($proposal->dispositions[0]->appInstanceId ?? 0);

            if ($target instanceof AppInstance) {
                $this->projection->prepareRouterCaddy($target, $destination, $destination);
            }
        }
    }

    /** @return list<int> */
    private function vacatedRouteIds(Route $route, SetRouteTargetsData $proposal): array
    {
        $ids = [];

        foreach ($proposal->targetIds as $appInstanceId) {
            $association = RouteTarget::query()
                ->where('app_instance_id', $appInstanceId)
                ->where('route_id', '!=', $route->id)
                ->first();

            if ($association !== null) {
                $ids[] = (int) $association->route_id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function removeAuthorized(SetRouteTargetsData $proposal): void
    {
        foreach ($proposal->dispositions as $disposition) {
            if (! $disposition->remove) {
                continue;
            }

            $instance = AppInstance::query()->find($disposition->appInstanceId);

            if (! $instance instanceof AppInstance) {
                continue;
            }

            $this->removals->execute($instance, force: true);
        }
    }

    private function rollbackPreparations(Route $route, SetRouteTargetsData $proposal): void
    {
        foreach ($this->preparedInstances($route, $proposal) as [$instance, $destination]) {
            $this->projection->rollbackCaddy($instance, $destination);
            $this->projection->rollbackCertificates($instance, $destination);
        }
    }

    /**
     * @return list<array{0: AppInstance, 1: Route}>
     */
    private function preparedInstances(Route $route, SetRouteTargetsData $proposal): array
    {
        $prepared = [];

        foreach ($this->guard->instances($proposal->targetIds) as $instance) {
            $prepared[] = [$instance, $route];
        }

        foreach ($proposal->dispositions as $disposition) {
            if ($disposition->remove || $disposition->routeId === null) {
                continue;
            }

            $instance = $this->guard->instances([$disposition->appInstanceId])->first();
            $destination = Route::query()->findOrFail($disposition->routeId);

            if ($instance instanceof AppInstance) {
                $prepared[] = [$instance, $destination];
            }
        }

        return $prepared;
    }

    /** @return list<AppInstance> */
    private function synchronizedInstances(Route $route, SetRouteTargetsData $proposal): array
    {
        $ids = $proposal->targetIds;

        foreach ($proposal->dispositions as $disposition) {
            if (! $disposition->remove) {
                $ids[] = $disposition->appInstanceId;
            }
        }

        return $this->guard->instances(array_values(array_unique($ids)))->values()->all();
    }

    private function complete(Route $route): void
    {
        $route->update([
            'target_set_intent' => null,
            'target_set_step' => RouteTargetSetStep::Completed->value,
            'failed_step' => null,
            'error_code' => null,
        ]);
    }

    private function advance(Route $route, RouteTargetSetStep $step): void
    {
        $route->update([
            'target_set_step' => $step->value,
            'failed_step' => null,
            'error_code' => null,
        ]);
        $route->refresh();
    }

    private function recordFailure(Route $route, string $step, string $errorCode): void
    {
        $route->update([
            'failed_step' => $step,
            'error_code' => $errorCode,
        ]);
    }

    private function errorCode(Throwable $exception): string
    {
        if ($exception instanceof ResourceOperationException) {
            return $exception->errorCode;
        }

        return 'route.target_set_failed';
    }

    private function rank(RouteTargetSetStep $step): int
    {
        return match ($step) {
            RouteTargetSetStep::Reserved => 0,
            RouteTargetSetStep::WorkloadPrepared => 1,
            RouteTargetSetStep::DatabaseCommitted => 2,
            RouteTargetSetStep::EnvironmentSynchronized => 3,
            RouteTargetSetStep::RouterPublished => 4,
            RouteTargetSetStep::VacatedPublished => 5,
            RouteTargetSetStep::Removal => 6,
            RouteTargetSetStep::Completed => 7,
        };
    }

    /** @return list<int> */
    private function ownerIds(Route $route, SetRouteTargetsData $proposal): array
    {
        $ids = [
            ...$route->targets->pluck('app_instance_id')->map(static fn (mixed $id): int => (int) $id)->all(),
            ...$proposal->targetIds,
            ...array_map(
                static fn (RouteTargetDispositionData $disposition): int => $disposition->appInstanceId,
                $proposal->dispositions,
            ),
        ];
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
