<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Data\Routes\RouteData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceState;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Routes\RouteAssociationGuard;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteTargetWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Instance;
use App\Models\InstanceRename;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class SetRouteTargetAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $environmentOperations,
        private RouteStateResolver $state,
        private RouteAssociationGuard $associations,
        private ?RecordEventBroadcaster $broadcaster = null,
        private ?MetricsFleetReconciler $metrics = null,
    ) {}

    public function execute(Route $route, int $instanceId): Route
    {
        if (! $route->isApp()) {
            throw new ResourceOperationException(
                errorCode: 'route.kind_unsupported',
                message: 'Only a Project Route can own Instance targets.',
                status: 409,
            );
        }

        $expectedTargetIds = $route
            ->targets()
            ->orderBy('instance_id')
            ->pluck('instance_id')
            ->map(static fn (mixed $id): int => StoredInteger::from($id))
            ->values()
            ->all();
        $expectedTargetIds = array_values($expectedTargetIds);
        $result = $this->environmentOperations->run(
            [...$expectedTargetIds, $instanceId],
            fn (): Route => $this->executeOwned($route, $instanceId, $expectedTargetIds),
        );

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::RouteUpdated,
            $result->id,
            RouteData::fromModel($result)->toArray(),
        );

        $this->metrics?->reconcile();

        return $result;
    }

    /** @param list<int> $expectedTargetIds */
    private function executeOwned(Route $route, int $instanceId, array $expectedTargetIds): Route
    {
        InstanceRename::assertAvailable([...$expectedTargetIds, $instanceId]);
        try {
            $updated = DB::transaction(function () use ($route, $instanceId, $expectedTargetIds): Route {
                $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
                $target = Instance::query()->with(['project', 'node'])->lockForUpdate()->findOrFail($instanceId);
                $currentTargetIds = $locked
                    ->targets()
                    ->orderBy('instance_id')
                    ->pluck('instance_id')
                    ->map(static fn (mixed $id): int => StoredInteger::from($id))
                    ->values()
                    ->all();

                if ($currentTargetIds !== $expectedTargetIds) {
                    throw new ResourceOperationException(
                        errorCode: 'env.owner_changed',
                        message: 'The Instance environment owner changed during the operation.',
                        status: 409,
                    );
                }

                if (! is_string($locked->app)) {
                    throw new ResourceOperationException('app.required', 'The Route requires recorded app ownership.', 409);
                }
                $app = $target->appConfiguration($locked->app)['name'];
                RouteTargetWebRoot::assertSupported($target, $app);
                $currentTarget = $locked->targets()->first();

                if ($currentTarget?->instance_id === $target->id) {
                    return $locked->load('targets');
                }

                if ($target->project_id !== $locked->project_id) {
                    throw new ResourceOperationException(
                        errorCode: 'route.target_app_conflict',
                        message: 'The Route target must belong to the Route Project.',
                        status: 409,
                    );
                }

                if ($target->status !== InstanceState::Active) {
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
                    $attributes['domain'] = $this->state->generatedDomain(
                        $target->project->slug,
                        $target->name,
                        $placement->effectiveTld,
                        $app,
                    );
                }

                $this->associations->assertTargetAssignable($locked, $target);
                $this->associations->assertTargetsDetachable($locked);
                app(RouteReconciliationGuard::class)->assertRouteMutable($locked);

                $nextDomain = $attributes['domain'] ?? $locked->domain;

                if ($nextDomain !== $locked->domain) {
                    $replacement = Route::query()->create([
                        'project_id' => $locked->project_id,
                        'app' => $app,
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
                    $replacement->targets()->create(['instance_id' => $target->id, 'position' => 0, 'app' => $replacement->app]);
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
                $locked->targets()->create(['instance_id' => $target->id, 'position' => 0, 'app' => $locked->app]);

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
