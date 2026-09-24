<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\ProductionPhpRuntimeManager;
use App\Domain\AppInstances\Removal\AppInstanceRemovalProjector;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
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
        private ?ProductionPhpRuntimeManager $productionPhp = null,
        private ?PublicRouteEdgeProjector $publicEdge = null,
        private ?MetricsFleetReconciler $metrics = null,
    ) {}

    public function clearRouteTarget(AppInstanceRemovalMember $member): string
    {
        $appInstance = AppInstance::query()->with('node')->findOrFail($member->app_instance_id);
        $route = $member->route_id === null
            ? null
            : Route::query()
                ->with(['targets.appInstance.node', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node'])
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

        if (
            $route->publication === RoutePublication::Public
            && $route->targets->count() <= 1
        ) {
            $this->removePublicEdge($route);
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

        // The stored Route, its publication record, and this open member render the unavailable
        // answer while the development removal runs.
        if (
            $member->environment === 'development'
            && $route->sites_published
            && ($removedTarget
            || $this->certificates->appInstanceCertificateExists($appInstance))
        ) {
            $this->caddy->converge($this->servingNode($route, $appInstance));
            $this->dns->converge();
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
        if ($appInstance->placedOnAppProd() && $appInstance->production_php_service !== null) {
            $this->productionPhp()->remove($appInstance);
        } else {
            $this->php->converge($appInstance->node);
        }
        $this->caddy->converge($appInstance->node);
        $this->certificates->removeAppInstance($appInstance);
        $this->metrics?->reconcile();
    }

    private function productionPhp(): ProductionPhpRuntimeManager
    {
        return $this->productionPhp ?? app(ProductionPhpRuntimeManager::class);
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
        $this->refreshIngress($route);
    }

    /**
     * Stored state changes first: the Route leaves the authoritative states and drops its
     * publication record, so the builds withdraw its sites before their certificates are removed
     * and the Route row is deleted.
     */
    private function removeRouteProjection(Route $route, AppInstance $appInstance): void
    {
        DB::transaction(static function () use ($route): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $locked->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);
            $route->setRawAttributes($locked->refresh()->getAttributes(), true);
        });
        $router = $route->cluster?->routerAssignment?->node;
        $this->caddy->converge($appInstance->node);

        if ($router instanceof Node && ! $router->is($appInstance->node)) {
            $this->caddy->converge($router);
        }

        $this->certificates->removeAppInstance($appInstance);
        $this->metrics?->reconcile();

        if ($router instanceof Node && ! $router->is($appInstance->node)) {
            $this->certificates->removeRouteRouter($route, $router);
        }

        if ($appInstance->placedOnAppDev()) {
            $this->firewall->remove($appInstance->node, $route->id);
        }

        $this->dns->converge();
        $this->refreshIngress($route);
    }

    private function removePublicEdge(Route $route): void
    {
        if ($route->publication !== RoutePublication::Public) {
            return;
        }

        if ($route->targets->count() <= 1 && $route->status !== RouteStatus::Retiring) {
            $route->update(['status' => RouteStatus::Retiring]);
        }

        $this->publicEdge()->removePublicEdge($route);
    }

    private function refreshIngress(Route $route): void
    {
        if ($route->publication !== RoutePublication::Public) {
            return;
        }

        $this->publicEdge()->prepareIngressFirewall($route);
        $ingress = $route->cluster?->ingressAssignment?->node;

        if ($ingress instanceof Node) {
            $this->caddy->converge($ingress);
        }
    }

    private function publicEdge(): PublicRouteEdgeProjector
    {
        return $this->publicEdge ?? app(PublicRouteEdgeProjector::class);
    }

    private function servingNode(Route $route, AppInstance $appInstance): Node
    {
        $router = $route->cluster?->routerAssignment?->node;

        return $router instanceof Node ? $router : $appInstance->node;
    }
}
