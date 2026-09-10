<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\Removal\AppInstanceRemovalProjector;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppDev\RemoteAppDevRouteFirewallManager;
use App\Models\AppInstance;
use App\Models\AppInstanceRemovalMember;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

final readonly class NativeAppInstanceRemovalProjector implements AppInstanceRemovalProjector
{
    public function __construct(
        private RemoteAppDevCaddyManager $caddy,
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevPhpFpmManager $php,
        private DnsmasqPrivateDnsManager $dns,
        private RemoteAppDevRouteFirewallManager $firewall,
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

        $removedTarget = (bool) DB::transaction(function () use ($route, $appInstance): bool {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $target = $locked->targets()->where('app_instance_id', $appInstance->id)->first();

            if ($target === null) {
                return false;
            }

            $target->delete();

            foreach ($locked->targets()->orderBy('position')->orderBy('id')->get() as $position => $remaining) {
                if ($remaining->position !== $position) {
                    $remaining->update(['position' => $position]);
                }
            }

            return true;
        });

        $route->refresh()->load(['targets.appInstance.node', 'cluster.routerAssignment.node']);

        if ($route->targets->isNotEmpty()) {
            if ($member->environment !== 'production') {
                throw new ResourceOperationException(
                    errorCode: 'instance.removal_conflict',
                    message: 'The recorded development Route target set changed during removal.',
                    status: 409,
                );
            }

            $this->publishRoute($route, $appInstance);

            return 'retained';
        }

        if (
            $member->environment === 'development'
            && ($removedTarget
            || $this->certificates->appInstanceCertificateExists($appInstance))
        ) {
            $this->caddy->convergeUnavailableRoute($this->servingNode($route, $appInstance), $route, $appInstance);
            $this->dns->convergeUnavailableRoute($route, $appInstance);
        }

        $this->removeRouteProjection($route, $appInstance);

        DB::transaction(function () use ($route): void {
            Route::query()->lockForUpdate()->findOrFail($route->id)->delete();
        });

        return 'deleted';
    }

    public function cleanupRuntime(AppInstanceRemovalMember $member): void
    {
        $appInstance = AppInstance::query()->with('node')->findOrFail($member->app_instance_id);
        $this->php->converge($appInstance->node);
        $this->caddy->converge($appInstance->node);
        $this->certificates->removeAppInstance($appInstance);
    }

    private function publishRoute(Route $route, AppInstance $departing): void
    {
        $router = $route->cluster?->routerAssignment?->node;
        $nodes = collect();

        if ($router instanceof Node) {
            $nodes->put($router->id, $router);
        }

        foreach ($route->targets as $target) {
            $nodes->put($target->appInstance->node->id, $target->appInstance->node);
        }

        $nodes->put($departing->node->id, $departing->node);

        foreach ($nodes as $node) {
            /** @var Node $node */
            $this->caddy->converge($node);
        }

        $this->certificates->removeAppInstance($departing);
        $this->dns->converge();
    }

    private function removeRouteProjection(Route $route, AppInstance $appInstance): void
    {
        $this->caddy->converge($appInstance->node);
        $this->certificates->removeAppInstance($appInstance);
        $router = $route->cluster?->routerAssignment?->node;

        if ($router instanceof Node && ! $router->is($appInstance->node)) {
            $this->caddy->converge($router);
            $this->certificates->removeRouteRouter($route, $router);
        }

        if ($appInstance->environment === 'development') {
            $this->firewall->remove($appInstance->node, $route->id);
        }

        $this->dns->converge();
    }

    private function servingNode(Route $route, AppInstance $appInstance): Node
    {
        $router = $route->cluster?->routerAssignment?->node;

        return $router instanceof Node ? $router : $appInstance->node;
    }
}
