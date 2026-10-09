<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppDev\RemoteAppDevRouteFirewallManager;
use App\Infrastructure\Compute\ProjectSandboxInstanceRemoval;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Route;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;

final readonly class NativeInstanceRemovalProjector implements InstanceRemovalProjector
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

    public function clearRouteTarget(InstanceRemovalMember $member): string
    {
        $instance = Instance::query()->with('node')->findOrFail($member->instance_id);
        $sandboxRemoval = $this->sandboxRemoval($instance);
        $route = $member->route_id === null
            ? null
            : Route::query()
                ->with(['targets.instance.node', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node'])
                ->find($member->route_id);

        if (! $route instanceof Route) {
            if ($member->route_id !== null) {
                return 'deleted';
            }

            throw new ResourceOperationException(
                errorCode: 'instance.removal_conflict',
                message: "Instance [{$member->name}] Route identity changed during removal.",
                status: 409,
            );
        }

        if (
            $route->publication === RoutePublication::Public
            && $route->targets->count() <= 1
        ) {
            $this->removePublicEdge($route);
        }

        $removedTarget = (bool) DB::transaction(function () use ($route, $instance): bool {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $target = $locked->targets()->where('instance_id', $instance->id)->first();

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

        $route->refresh()->load(['targets.instance.node', 'cluster.routerAssignment.node']);

        if ($route->targets->isNotEmpty()) {
            if ($member->environment !== 'production') {
                throw new ResourceOperationException(
                    errorCode: 'instance.removal_conflict',
                    message: 'The recorded development Route target set changed during removal.',
                    status: 409,
                );
            }

            $this->publishRoute($route, $instance);

            return 'retained';
        }

        // The stored Route, its publication record, and this open member render the unavailable
        // answer while the development removal runs.
        if (
            $member->environment === 'development'
            && $route->sites_published
            && ($removedTarget || $sandboxRemoval
            || $this->certificates->instanceCertificateExists($instance))
        ) {
            $serving = $this->servingNode($route, $instance);
            if (! $sandboxRemoval || ! $serving->is($instance->node)) {
                $this->caddy->build($serving);
            }
            $this->dns->converge();
        }

        $this->removeRouteProjection($route, $instance, $sandboxRemoval);

        DB::transaction(function () use ($route): void {
            Route::query()->lockForUpdate()->findOrFail($route->id)->delete();
        });

        return 'deleted';
    }

    /**
     * Runs after `route_target_clear`, which leaves the Instance with no Route target, so stored state
     * renders no pool for it. This convergence drops the pool and reloads PHP-FPM while the working
     * directory still exists.
     */
    public function withdrawPhpPool(InstanceRemovalMember $member): void
    {
        $instance = Instance::query()->with('node')->findOrFail($member->instance_id);

        if ($member->environment !== 'development' || $instance->placedOnAppProd()) {
            return;
        }

        $this->convergeDevelopmentPhp($instance);
    }

    public function cleanupRuntime(InstanceRemovalMember $member): void
    {
        $instance = Instance::query()->with('node')->findOrFail($member->instance_id);
        if ($this->sandboxRemoval($instance)) {
            return;
        }
        if ($instance->placedOnAppProd()) {
            if (! ProductionPhpRuntimeIdentity::isAbsent($instance)) {
                if (
                    $instance->selected_php_version !== null
                    && (! is_string($instance->production_php_service) || $instance->production_php_service === '')
                ) {
                    throw new ResourceOperationException(
                        errorCode: 'app-prod.php_service_missing',
                        message: 'The production PHP Instance has no recorded dedicated PHP-FPM service.',
                        status: 409,
                    );
                }

                $this->productionPhp()->remove($instance);
            }
        } else {
            $this->convergeDevelopmentPhp($instance);
        }
        $this->caddy->build($instance->node);
        $this->certificates->removeInstance($instance);
        $this->metrics?->reconcile();
    }

    /**
     * Stored state renders no pool for the removed Instance, so a convergence that skipped another
     * site's pool for a missing directory has still withdrawn this one. Doctor reports the skipped
     * pool; it must not keep this removal open.
     */
    private function convergeDevelopmentPhp(Instance $instance): void
    {
        try {
            $this->php->converge($instance->node);
        } catch (RuntimeConvergenceException $exception) {
            if ($exception->errorCode !== RemoteAppDevPhpFpmManager::PoolDirectoryMissing) {
                throw $exception;
            }
        }
    }

    private function productionPhp(): ProductionPhpRuntimeManager
    {
        return $this->productionPhp ?? app(ProductionPhpRuntimeManager::class);
    }

    private function publishRoute(Route $route, Instance $departing): void
    {
        $router = $route->cluster?->routerAssignment?->node;
        $nodes = collect();

        if ($router instanceof Node) {
            $nodes->put($router->id, $router);
        }

        foreach ($route->targets as $target) {
            $nodes->put($target->instance->node->id, $target->instance->node);
        }

        $nodes->put($departing->node->id, $departing->node);

        foreach ($nodes as $node) {
            $this->caddy->build($node);
        }

        $this->certificates->removeInstance($departing);
        $this->dns->converge();
        $this->refreshIngress($route);
    }

    /**
     * Stored state changes first: the Route leaves the authoritative states and drops its
     * publication record, so the builds withdraw its sites before their certificates are removed
     * and the Route row is deleted. A placement change that waits for its withdrawal also leaves
     * sites and certificates on its second placement, so removal withdraws those too.
     */
    private function removeRouteProjection(Route $route, Instance $instance, bool $sandboxRemoval = false): void
    {
        $route->loadMissing(['transitionCluster.routerAssignment.node']);
        $transitionRouter = $route->transitionCluster?->routerAssignment?->node;
        $hadTransition = $route->hasPlacementTransition();
        DB::transaction(static function () use ($route): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $locked->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);
            $route->setRawAttributes($locked->refresh()->getAttributes(), true);
        });
        $router = $route->cluster?->routerAssignment?->node;
        $routers = collect([$router, $transitionRouter])
            ->filter(static fn (?Node $node): bool => $node instanceof Node && ! $node->is($instance->node))
            ->unique(static fn (Node $node): int => $node->id)
            ->values();
        if (! $sandboxRemoval) {
            $this->caddy->build($instance->node);
        }

        foreach ($routers as $serving) {
            $this->caddy->build($serving);
        }

        if (! $sandboxRemoval) {
            $this->certificates->removeInstance($instance);
        }

        if ($hadTransition) {
            $this->certificates->removeHostnameChange($instance, $route);
        }

        $this->metrics?->reconcile();

        foreach ($routers as $serving) {
            $this->certificates->removeRouteRouter($route, $serving);

            if ($hadTransition && ! ($router instanceof Node && $serving->is($router))) {
                $this->certificates->removeRouteRouterHostnameChange($route, $serving);
            }
        }

        if (! $sandboxRemoval && $instance->placedOnAppDev()) {
            $this->firewall->remove($instance->node, $route->id);
        }

        $this->dns->converge();
        $this->refreshIngress($route);
    }

    /**
     * Whether the Instance's own Node must be left alone: an owned sandbox, or a workspace on a task VM
     * that is being destroyed, whose VM is already gone.
     */
    private function sandboxRemoval(Instance $instance): bool
    {
        if (! InstanceSandboxGuard::isSandbox($instance)) {
            return $instance->status === InstanceState::Removing
                && TaskVm::query()->where('node_id', $instance->node_id)->where('state', TaskVmState::Destroying)->exists();
        }
        $member = $instance->removalMember()->first();
        if ($member === null) {
            throw new ResourceOperationException('instance.sandbox_managed', 'The sandbox has no accepted removal journal.', 409);
        }
        ProjectSandboxInstanceRemoval::assertJournal($instance, $member);

        return true;
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
            $this->caddy->build($ingress);
        }
    }

    private function publicEdge(): PublicRouteEdgeProjector
    {
        return $this->publicEdge ?? app(PublicRouteEdgeProjector::class);
    }

    private function servingNode(Route $route, Instance $instance): Node
    {
        $router = $route->cluster?->routerAssignment?->node;

        return $router instanceof Node ? $router : $instance->node;
    }
}
