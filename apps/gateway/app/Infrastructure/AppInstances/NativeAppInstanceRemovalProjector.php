<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\Removal\AppInstanceRemovalProjector;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Models\AppInstance;
use App\Models\AppInstanceRemovalMember;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

/** @mago-expect lint:cyclomatic-complexity Projection distinguishes shared and final Routes across resumable checkpoints. */
final readonly class NativeAppInstanceRemovalProjector implements AppInstanceRemovalProjector
{
    public function __construct(
        private RemoteAppDevCaddyManager $caddy,
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevPhpFpmManager $php,
        private DnsmasqPrivateDnsManager $dns,
    ) {}

    public function clearRouteTarget(AppInstanceRemovalMember $member): string
    {
        $appInstance = AppInstance::query()->with('node')->findOrFail($member->app_instance_id);
        $route = $member->route_id === null
            ? null
            : Route::query()
                ->with(['targets.appInstance.node', 'cluster.routerAssignment.node'])
                ->find($member->route_id);

        if (! $route instanceof Route) {
            if ($member->route_id !== null) {
                return 'deleted';
            }

            throw new ResourceOperationException(
                errorCode: 'instance.removal_conflict',
                message: "AppInstance [{$member->name}] Route identity changed during removal.",
                status: 409,
            );
        }

        DB::transaction(function () use ($route, $appInstance): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $target = $locked->targets()->where('app_instance_id', $appInstance->id)->first();

            if ($target !== null) {
                $target->delete();
            }

            foreach ($locked->targets()->orderBy('position')->orderBy('id')->get() as $position => $remaining) {
                if ($remaining->position !== $position) {
                    $remaining->update(['position' => $position]);
                }
            }
        });

        $route->refresh()->load(['targets.appInstance.node', 'cluster.routerAssignment.node']);

        if ($route->targets->isNotEmpty()) {
            $this->publishRoute($route);

            return 'retained';
        }

        if ($member->environment === 'development') {
            $servingNode = $this->servingNode($route, $appInstance);
            $this->caddy->convergeUnavailableRoute($servingNode, $route, $appInstance);
            $this->dns->convergeUnavailableRoute($route, $appInstance);
        }

        DB::transaction(function () use ($route): void {
            Route::query()->lockForUpdate()->findOrFail($route->id)->delete();
        });

        $this->removeRouteProjection($route, $appInstance);

        return 'deleted';
    }

    public function cleanupRuntime(AppInstanceRemovalMember $member): void
    {
        if ($member->environment !== 'development') {
            return;
        }

        $appInstance = AppInstance::query()->with('node')->findOrFail($member->app_instance_id);
        $this->php->converge($appInstance->node);
        $this->caddy->converge($appInstance->node);
        $this->certificates->removeAppInstance($appInstance);
    }

    private function publishRoute(Route $route): void
    {
        $router = $route->cluster?->routerAssignment?->node;

        if ($router instanceof Node) {
            $this->caddy->converge($router);
        }

        foreach ($route->targets as $target) {
            $appInstance = $target->appInstance;

            if ($appInstance->environment === 'development') {
                $this->caddy->converge($appInstance->node);
            }
        }

        $this->dns->converge();
    }

    private function removeRouteProjection(Route $route, AppInstance $appInstance): void
    {
        if ($appInstance->environment === 'development') {
            $this->caddy->converge($appInstance->node);
        }

        $router = $route->cluster?->routerAssignment?->node;

        if ($router instanceof Node && ! $router->is($appInstance->node)) {
            $this->caddy->converge($router);
            $this->certificates->removeRouteRouter($route, $router);
        }

        $this->dns->converge();
    }

    private function servingNode(Route $route, AppInstance $appInstance): Node
    {
        $router = $route->cluster?->routerAssignment?->node;

        return $router instanceof Node ? $router : $appInstance->node;
    }
}
