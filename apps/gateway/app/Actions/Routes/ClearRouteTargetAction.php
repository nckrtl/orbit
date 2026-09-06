<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\Routes\RouteReconciliationGuard;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

final readonly class ClearRouteTargetAction
{
    public function execute(Route $route): Route
    {
        /** @var Route $updated */
        $updated = DB::transaction(function () use ($route): Route {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);

            if ($locked->targets()->exists()) {
                app(RouteReconciliationGuard::class)->assertRouteMutable($locked);
            }
            $locked->targets()->delete();

            return $locked->refresh()->load('targets');
        });

        return $updated;
    }
}
