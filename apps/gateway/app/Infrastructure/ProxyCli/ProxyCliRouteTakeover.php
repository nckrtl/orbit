<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Actions\Routes\RemoveRouteAction;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\ProxyCli\ProxyCliHostnameRoute;
use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Models\Node;
use App\Models\Route;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves the collector hostname from the custom proxy Route that serves it to the collector site without a
 * gap (ADR 0145). The Route's site and the collector site sit in different fragments, and Caddy refuses both
 * on one listener, so the collector publication writes the Route's fragment without the Route in the same
 * reload. The Route is then removed like any other.
 */
final readonly class ProxyCliRouteTakeover
{
    public function __construct(
        private DevelopmentProjectionOperationLock $projection,
        private RemoteAppDevCaddyManager $appDev,
        private RemoveRouteAction $removal,
        private ProxyCliHostnameRoute $routes = new ProxyCliHostnameRoute,
    ) {}

    /**
     * Withdraws the Route's site and runs the collector publication with the Node's `app-dev.caddy` fragment
     * rendered without it. When the publication fails, the Route serves again and keeps its state.
     *
     * @param  Closure(string): void  $publish
     */
    public function publish(Route $route, Node $node, Closure $publish, int $port = ProxyCliProcess::PORT): void
    {
        $this->projection->run(function () use ($route, $node, $publish, $port): void {
            // Another operation may have changed the Route since enable checked it.
            if ($this->routes->takeoverCandidate($node, $port)?->id !== $route->id) {
                $publish($this->appDev->render($node));

                return;
            }

            $previous = $this->withdraw($route);

            try {
                $publish($this->appDev->render($node));
            } catch (Throwable $exception) {
                $this->restore($route, $previous);

                throw $exception;
            }
        });
    }

    /** Removes the Route's DNS answer, certificate, and record once the collector site serves its name. */
    public function remove(Route $route): void
    {
        $fresh = Route::query()->find($route->id);

        if ($fresh instanceof Route) {
            $this->removal->execute($fresh);
        }
    }

    /** @return array{status: RouteStatus, sites_published: bool} */
    private function withdraw(Route $route): array
    {
        /** @var array{status: RouteStatus, sites_published: bool} $previous */
        $previous = DB::transaction(static function () use ($route): array {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $previous = ['status' => $locked->status, 'sites_published' => $locked->sites_published];
            $locked->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);

            return $previous;
        });

        return $previous;
    }

    /** @param array{status: RouteStatus, sites_published: bool} $previous */
    private function restore(Route $route, array $previous): void
    {
        DB::transaction(static function () use ($route, $previous): void {
            Route::query()->lockForUpdate()->findOrFail($route->id)->update($previous);
        });
    }
}
