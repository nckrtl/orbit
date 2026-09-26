<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Data\Routes\RouteData;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Routes\RouteAssociationGuard;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteTargetWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class SetRouteTargetAction
{
    public function __construct(
        private AppInstanceEnvironmentOperationLock $environmentOperations,
        private RouteStateResolver $state,
        private RouteAssociationGuard $associations,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(Route $route, int $appInstanceId): Route
    {
        if (! $route->isApp()) {
            throw new ResourceOperationException(
                errorCode: 'route.kind_unsupported',
                message: 'Only a Project Route can own Instance targets.',
                status: 409,
            );
        }

        /** @var list<int> $expectedTargetIds */
        $expectedTargetIds = $route
            ->targets()
            ->orderBy('app_instance_id')
            ->pluck('app_instance_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $result = $this->environmentOperations->run(
            [...$expectedTargetIds, $appInstanceId],
            fn (): Route => $this->executeOwned($route, $appInstanceId, $expectedTargetIds),
        );

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::RouteUpdated,
            $result->id,
            RouteData::fromModel($result)->toArray(),
        );

        return $result;
    }

    /** @param list<int> $expectedTargetIds */
    private function executeOwned(Route $route, int $appInstanceId, array $expectedTargetIds): Route
    {
        try {
            /** @var Route $updated */
            $updated = DB::transaction(function () use ($route, $appInstanceId, $expectedTargetIds): Route {
                $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
                $target = AppInstance::query()->with(['app', 'node'])->lockForUpdate()->findOrFail($appInstanceId);
                $currentTargetIds = $locked
                    ->targets()
                    ->orderBy('app_instance_id')
                    ->pluck('app_instance_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all();

                if ($currentTargetIds !== $expectedTargetIds) {
                    throw new ResourceOperationException(
                        errorCode: 'env.owner_changed',
                        message: 'The Instance environment owner changed during the operation.',
                        status: 409,
                    );
                }

                RouteTargetWebRoot::assertSupported($target);
                $currentTarget = $locked->targets()->first();

                if ($currentTarget?->app_instance_id === $target->id) {
                    return $locked->load('targets');
                }

                if ($target->app_id !== $locked->app_id) {
                    throw new ResourceOperationException(
                        errorCode: 'route.target_app_conflict',
                        message: 'The Route target must belong to the Route Project.',
                        status: 409,
                    );
                }

                if ($target->status !== AppInstanceState::Active) {
                    throw new ResourceOperationException(
                        errorCode: 'route.target_inactive',
                        message: 'The Route target must be active.',
                        status: 409,
                    );
                }

                $placement = $this->state->forNode($target->node);

                if ($placement->clusterId !== null) {
                    $this->state->assertRouter($placement->clusterId);
                }

                $attributes = [
                    'node_id' => $placement->nodeId,
                    'cluster_id' => $placement->clusterId,
                ];

                if ($locked->provenance === RouteProvenance::Generated) {
                    $attributes['generation_basis_node_id'] = $target->node_id;
                    $attributes['domain'] = $target->migration_required
                        ? $locked->domain
                        : $this->state->generatedDomain(
                            $target->app->slug,
                            $target->name,
                            $placement->effectiveTld,
                        );
                }

                $this->associations->assertTargetAssignable($locked, $target);
                $this->associations->assertTargetsDetachable($locked);
                app(RouteReconciliationGuard::class)->assertRouteMutable($locked);

                $nextDomain = $attributes['domain'] ?? $locked->domain;

                if ($nextDomain !== $locked->domain) {
                    $replacement = Route::query()->create([
                        'app_id' => $locked->app_id,
                        'node_id' => $attributes['node_id'],
                        'cluster_id' => $attributes['cluster_id'],
                        'generation_basis_node_id' => $attributes['generation_basis_node_id'] ?? null,
                        'domain' => $nextDomain,
                        'provenance' => $locked->provenance,
                        'publication' => $locked->publication,
                        'status' => RouteStatus::Pending,
                        'replaces_route_id' => $locked->id,
                        'replacement_step' => RouteReplacementStep::Reserved,
                    ]);
                    $replacement->targets()->create(['app_instance_id' => $target->id, 'position' => 0]);
                    $locked->targets()->delete();
                    $locked->delete();
                    $replacement->update([
                        'replaces_route_id' => null,
                        'replacement_step' => null,
                    ]);

                    return $replacement->refresh()->load('targets');
                }

                unset($attributes['domain']);
                $locked->update($attributes);
                $locked->targets()->delete();
                $locked->targets()->create(['app_instance_id' => $target->id, 'position' => 0]);

                return $locked->refresh()->load('targets');
            });

            return $updated;
        } catch (QueryException $exception) {
            throw new ResourceOperationException(
                errorCode: 'route.target_conflict',
                message: 'The Route target proposal conflicts with existing Route state.',
                status: 409,
                previous: $exception,
            );
        }
    }
}
