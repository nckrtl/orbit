<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\Routes\RouteAssociationGuard;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

final readonly class RemoveRouteAction
{
    public function __construct(
        private RouteAssociationGuard $associations,
    ) {}

    public function execute(Route $route): Route
    {
        /** @var Route $removed */
        $removed = DB::transaction(function () use ($route): Route {
            $locked = Route::query()->with('targets')->lockForUpdate()->findOrFail($route->id);
            $this->associations->assertTargetsDetachable($locked);
            app(RouteReconciliationGuard::class)->assertRouteMutable($locked);
            $locked->delete();

            return $locked;
        });

        return $removed;
    }
}
