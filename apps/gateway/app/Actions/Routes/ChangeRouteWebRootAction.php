<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionCloneRouteProjector;
use App\Domain\Instances\ProductionRouteProjector;
use App\Domain\Instances\ProductionWebRootManager;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteTargetWebRoot;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Models\Instance;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sets or clears the web root of an active Route and converges its site, pool, and `APP_URL`. On a
 * production Instance it also prepares the web root in the selected release. A failed convergence
 * restores the previous web root and converges it again.
 */
final readonly class ChangeRouteWebRootAction
{
    public function __construct(
        private DevelopmentProjectionOperationLock $projection,
        private DevelopmentRouteProjector $routes,
        private SynchronizeRouteWebRootUrlsAction $urls,
        private RemoteAppDevCertificateManager $certificates,
        private ProductionRouteProjector $productionRoutes,
        private ProductionCloneRouteProjector $productionSites,
        private ProductionWebRootManager $productionWebRoots,
    ) {}

    public function execute(Route $route, ?string $webRoot): Route
    {
        $webRoot = RouteWebRoot::normalize($webRoot);

        return $this->projection->run(function () use ($route, $webRoot): Route {
            [$route, $instance, $previous] = DB::transaction(fn (): array => $this->store($route, $webRoot));

            if ($previous === $webRoot) {
                return $route;
            }

            try {
                $this->converge($instance, $route);
            } catch (Throwable $exception) {
                $route->update(['web_root' => $previous]);

                try {
                    $this->converge($instance, $route);
                } catch (Throwable) {
                    // The original failure explains the request; a later converge repairs the rest.
                }

                throw $exception;
            }

            if ($previous !== null && $webRoot === null) {
                $this->certificates->removeRouteLeaf($route, $instance->node);
            }

            return $route->refresh()->load('targets');
        });
    }

    /** @return array{Route, Instance, ?string} */
    private function store(Route $route, ?string $webRoot): array
    {
        $locked = Route::query()->with('targets.instance.project', 'targets.instance.node')->lockForUpdate()->findOrFail($route->id);
        $instance = $locked->targets->first()?->instance;

        if (
            ! $locked->isApp()
            || $locked->status !== RouteStatus::Active
            || $locked->targets->count() !== 1
            || $locked->replaces_route_id !== null
            || $locked->replaced_by_route_id !== null
            || ! $instance instanceof Instance
            || $instance->status !== InstanceState::Active
        ) {
            throw new ResourceOperationException(
                errorCode: 'route.web_root_unsupported',
                message: 'A Route web root can change only on an active Route with one active Instance target.',
                status: 409,
            );
        }

        $previous = $locked->web_root;

        if ($previous === $webRoot) {
            return [$locked, $instance, $previous];
        }

        if ($webRoot === null) {
            RouteTargetWebRoot::assertSupported($instance);
            $own = Route::query()
                ->whereKeyNot($locked->id)
                ->whereNull('web_root')
                ->whereHas('targets', static fn ($query) => $query->where('instance_id', $instance->id))
                ->first();

            if ($own instanceof Route) {
                throw new ResourceOperationException(
                    errorCode: 'route.target_conflict',
                    message: "Instance [{$instance->id}] is already associated with Route [{$own->id}] without a web root.",
                    status: 409,
                );
            }
        } elseif ($previous === null && $instance->requiresRoute()) {
            throw new ResourceOperationException(
                errorCode: 'route.web_root_conflict',
                message: "Instance [{$instance->id}] keeps Route [{$locked->id}] for its effective root. Create another Route with a web root instead.",
                status: 409,
            );
        }

        $locked->update(['web_root' => $webRoot]);

        return [$locked, $instance, $previous];
    }

    private function converge(Instance $instance, Route $route): void
    {
        if ($instance->placedOnAppProd()) {
            $this->productionRoutes->prepareCertificate($instance, $route);
            $this->productionWebRoots->prepare($instance);
            $this->urls->execute($instance);
            $this->productionRoutes->prepareRuntime($instance, $route);
            $this->productionSites->prepareWorkloadCaddy($instance, $route);

            return;
        }

        $this->routes->converge($instance, $route);
        $this->urls->execute($instance);
    }
}
