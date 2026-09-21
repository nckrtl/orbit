<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Data\Routes\RouteData;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
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
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(Route $route): Route
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
            $expectedTargetIds,
            fn (): Route => $this->executeOwned($route, $expectedTargetIds),
        );

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::RouteUpdated,
            $result->id,
            RouteData::fromModel($result)->toArray(),
        );

        return $result;
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
                    message: 'The Instance environment owner changed during the operation.',
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
