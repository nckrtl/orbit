<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class SetRouteTargetAction
{
    public function __construct(
        private RouteStateResolver $state,
    ) {}

    public function execute(Route $route, int $appInstanceId): Route
    {
        try {
            /** @var Route $updated */
            $updated = DB::transaction(function () use ($route, $appInstanceId): Route {
                $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
                $target = AppInstance::query()->with(['app', 'node'])->lockForUpdate()->findOrFail($appInstanceId);
                $currentTarget = $locked->targets()->first();

                if ($currentTarget?->app_instance_id === $target->id) {
                    return $locked->load('targets');
                }

                app(RouteReconciliationGuard::class)->assertRouteMutable($locked);

                if ($target->app_id !== $locked->app_id) {
                    throw new ResourceOperationException(
                        errorCode: 'route.target_app_conflict',
                        message: 'The Route target must belong to the Route App.',
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
                    $attributes['hostname'] = $this->state->generatedHostname(
                        $target->app->slug,
                        (string) $target->app->main_branch,
                        $target->name,
                        $placement->effectiveTld,
                    );
                }

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
