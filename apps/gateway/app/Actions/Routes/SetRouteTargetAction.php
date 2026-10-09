<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Data\Routes\RouteData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\DevelopmentSourceAccess;
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
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Instance;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SetRouteTargetAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $environmentOperations,
        private RouteStateResolver $state,
        private RouteAssociationGuard $associations,
        private DevelopmentSourceAccess $sourceAccess,
        private DevelopmentProjectionOperationLock $projections,
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
        // The projection owner is taken before the Route is read, so contention returns before any
        // write. A source-access failure comes after the commit: the stored target is still
        // announced, and the failure is reported afterwards.
        [$result, $accessFailure] = $this->environmentOperations->run(
            [...$expectedTargetIds, $instanceId],
            fn (): array => $this->projections->run(
                fn (): array => $this->executeOwned($route, $instanceId, $expectedTargetIds),
            ),
        );

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::RouteUpdated,
            $result->id,
            RouteData::fromModel($result)->toArray(),
        );

        $this->metrics?->reconcile();

        if ($accessFailure instanceof Throwable) {
            throw $accessFailure;
        }

        return $result;
    }

    /**
     * @param  list<int>  $expectedTargetIds
     * @return array{Route, ?Throwable}
     */
    private function executeOwned(Route $route, int $instanceId, array $expectedTargetIds): array
    {
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

                if ($locked->hasWebRoot()) {
                    RouteWebRoot::assertSupportedTarget($target);
                } else {
                    RouteTargetWebRoot::assertSupported($target);
                }

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
                    );
                }

                if (! $locked->hasWebRoot()) {
                    $this->associations->assertTargetAssignable($locked, $target);
                }

                $this->associations->assertTargetsDetachable($locked);
                app(RouteReconciliationGuard::class)->assertRouteMutable($locked);

                $nextDomain = $attributes['domain'] ?? $locked->domain;

                if ($nextDomain !== $locked->domain) {
                    $replacement = Route::query()->create([
                        'project_id' => $locked->project_id,
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
                    $replacement->targets()->create(['instance_id' => $target->id, 'position' => 0]);
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
                $locked->targets()->create(['instance_id' => $target->id, 'position' => 0]);

                return $locked->refresh()->load('targets');
            });
        } catch (QueryException $exception) {
            throw new ResourceOperationException(
                errorCode: 'route.target_conflict',
                message: 'The Route target proposal conflicts with existing Route state.',
                status: 409,
                previous: $exception,
            );
        }

        try {
            $this->grantSourceAccess($updated, $instanceId);
        } catch (RuntimeConvergenceException $exception) {
            return [$updated, new RuntimeConvergenceException(
                step: $exception->step,
                errorCode: $exception->errorCode,
                message: "{$exception->getMessage()} The Route target is stored. Set the same target on Route [{$updated->domain}] again to grant the access.",
                previous: $exception,
                result: $exception->result,
            )];
        } catch (Throwable $exception) {
            return [$updated, $exception];
        }

        return [$updated, null];
    }

    /**
     * Setting a target does not build Caddy, but the next build on the target's Node serves the
     * Route's published sites with it. Route convergence on that Node walks only its own checkout,
     * so the new target's Web root is made readable here, after the commit and under the caller's
     * projection owner. A retry of the same target on a Route that is not active grants again, so
     * it repairs a failed grant. An active Route only accepts its current target, as a no-op, and
     * its convergence already granted that access.
     */
    private function grantSourceAccess(Route $route, int $instanceId): void
    {
        if ($route->status === RouteStatus::Active) {
            return;
        }

        $target = Instance::query()->with('node')->findOrFail($instanceId);

        if (! $target->placedOnAppDev()) {
            return;
        }

        $this->sourceAccess->grant($target);
    }
}
