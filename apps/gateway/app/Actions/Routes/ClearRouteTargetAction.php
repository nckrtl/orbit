<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Routes\RouteAssociationGuard;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

final readonly class ClearRouteTargetAction
{
    public function __construct(
        private AppInstanceEnvironmentOperationLock $environmentOperations,
        private RouteAssociationGuard $associations,
    ) {}

    public function execute(Route $route): Route
    {
        /** @var list<int> $expectedTargetIds */
        $expectedTargetIds = $route
            ->targets()
            ->orderBy('app_instance_id')
            ->pluck('app_instance_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $this->environmentOperations->run(
            $expectedTargetIds,
            fn (): Route => $this->executeOwned($route, $expectedTargetIds),
        );
    }

    /** @param list<int> $expectedTargetIds */
    private function executeOwned(Route $route, array $expectedTargetIds): Route
    {
        /** @var Route $updated */
        $updated = DB::transaction(function () use ($route, $expectedTargetIds): Route {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
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
                    message: 'The AppInstance environment owner changed during the operation.',
                    status: 409,
                );
            }

            if (! $locked->targets()->exists()) {
                return $locked->load('targets');
            }

            $this->associations->assertTargetsDetachable($locked);
            app(RouteReconciliationGuard::class)->assertRouteMutable($locked);
            $locked->targets()->delete();

            return $locked->refresh()->load('targets');
        });

        return $updated;
    }
}
