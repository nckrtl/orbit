<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\Analytics\AnalyticsTrackingUpstream;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\CustomProxyUpstream;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Models\AppInstance;
use App\Models\AppInstanceRemovalMember;
use App\Models\AppInstanceTransfer;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class AppDevSiteRepository
{
    public function __construct(
        private PublicRouteEligibility $eligibility = new PublicRouteEligibility,
        private IngressSiteRepository $ingressSites = new IngressSiteRepository,
    ) {}

    /**
     * @param  array<int, int>  $routerOverrides
     * @return Collection<int, AppDevSite>
     */
    public function forNode(
        Node $node,
        ?Route $pendingRoute = null,
        ?AppInstance $unavailableInstance = null,
        ?Route $additionalRoute = null,
        array $routerOverrides = [],
    ): Collection {
        return $this->sites($node, $pendingRoute, $unavailableInstance, $additionalRoute, $routerOverrides);
    }

    /**
     * @param  array<int, int>  $routerOverrides
     * @return Collection<int, AppDevSite>
     */
    public function all(
        ?Route $pendingRoute = null,
        ?AppInstance $unavailableInstance = null,
        ?Route $additionalRoute = null,
        array $routerOverrides = [],
    ): Collection {
        return $this->sites(null, $pendingRoute, $unavailableInstance, $additionalRoute, $routerOverrides);
    }

    /**
     * @param  array<int, int>  $routerOverrides
     * @return Collection<int, AppDevSite>
     */
    private function sites(
        ?Node $node,
        ?Route $pendingRoute,
        ?AppInstance $unavailableInstance,
        ?Route $additionalRoute,
        array $routerOverrides = [],
    ): Collection {
        /** @var Collection<int, AppDevSite> $sites */
        $sites = collect();

        $routeQuery = Route::query()
            ->with([
                'targets.appInstance.app',
                'targets.appInstance.node',
                'cluster.routerAssignment.node',
                'cluster.ingressAssignment.node',
                'customProxy',
                'analyticsTracking',
                'node',
            ])
            ->where(static function (Builder $query) use ($pendingRoute): void {
                $query->whereIn('status', [
                    RouteStatus::Active->value,
                    RouteStatus::Activating->value,
                    RouteStatus::Retiring->value,
                ])->orWhere(static function (Builder $query): void {
                    $query
                        ->whereIn('status', [
                            RouteStatus::Pending->value,
                            RouteStatus::Failed->value,
                        ])
                        ->whereNotNull('replaces_route_id');
                });

                if ($pendingRoute instanceof Route) {
                    $query->orWhere('id', $pendingRoute->id);
                }
            });

        if ($node instanceof Node) {
            $nodeId = $node->id;
            $overrideClusterIds = array_keys(array_filter(
                $routerOverrides,
                static fn (int $routerId): bool => $routerId === $nodeId,
            ));
            $routeQuery->where(static function (Builder $query) use (
                $nodeId,
                $pendingRoute,
                $unavailableInstance,
                $overrideClusterIds,
            ): void {
                $query->where(static function (Builder $query) use (
                    $nodeId,
                    $pendingRoute,
                    $unavailableInstance,
                    $overrideClusterIds,
                ): void {
                    $query
                        ->where('routes.node_id', $nodeId)
                        ->orWhereHas(
                            'targets.appInstance',
                            static fn (Builder $query): Builder => $query->where('node_id', $nodeId),
                        )
                        ->orWhereHas(
                            'cluster.routerAssignment',
                            static fn (Builder $query): Builder => $query->where('node_id', $nodeId),
                        )
                        ->orWhereHas(
                            'cluster.ingressAssignment',
                            static fn (Builder $query): Builder => $query->where('node_id', $nodeId),
                        );

                    if ($overrideClusterIds !== []) {
                        $query->orWhereIn('cluster_id', $overrideClusterIds);
                    }

                    if (
                        $pendingRoute instanceof Route
                        && $unavailableInstance instanceof AppInstance
                        && $unavailableInstance->node_id === $nodeId
                    ) {
                        $query->orWhere('routes.id', $pendingRoute->id);
                    }
                });
            });
        }

        // A Route only becomes Retiring at cutover, when another Route already answers for it. Serving
        // it past that point means serving a domain whose certificate now names its replacement, which
        // sends Caddy to automatic HTTPS for a private Orbit domain.
        $routeQuery->where(static function (Builder $query): void {
            $query->where('status', '!=', RouteStatus::Retiring->value)
                ->orWhere(static function (Builder $query): void {
                    $query->whereNull('replaced_by_route_id')
                        ->whereNotIn('id', AppInstanceTransfer::query()
                            ->select('source_route_id')
                            ->whereNotNull('cutover_at')
                            ->whereColumn('source_route_id', '!=', 'destination_route_id'));
                });
        });

        $routes = $routeQuery->get();
        /** @var Collection<int, Route> $routes */
        foreach ($routes as $route) {
            if ($route->kind === RouteKind::CustomProxy) {
                $site = $this->customProxySite($route);

                if ($site instanceof AppDevSite) {
                    $sites->push($site);
                }

                continue;
            }

            if ($route->kind === RouteKind::AnalyticsTracking) {
                foreach ($this->analyticsTrackingSites($route, $pendingRoute, $routerOverrides) as $site) {
                    $sites->push($site);
                }

                continue;
            }

            $targets = $route
                ->targets
                ->map(static fn ($targetRow) => $targetRow->appInstance)
                ->filter(
                    static fn ($target): bool => (
                        $target instanceof AppInstance
                        && is_string($target->node->wireguard_ip)
                        && in_array(
                            $target->status,
                            [AppInstanceState::SourceResolved, AppInstanceState::Active],
                            true,
                        )
                    ),
                )
                ->values();
            $router = $this->routerFor($route, $routerOverrides);
            $ingress = $route->cluster !== null
                ? $this->eligibility->activeIngress($route->cluster)
                : null;
            $hasPublicIngress = $this->publishesIngress($route)
                && $ingress instanceof Node;
            $ingressSharesRouter = $hasPublicIngress && $router instanceof Node && $ingress->is($router);
            $localTargets = $router instanceof Node
                ? $targets->filter(static fn (AppInstance $target): bool => $router->is($target->node))
                : collect();
            $remoteTargets = $router instanceof Node
                ? $targets->filter(static fn (AppInstance $target): bool => ! $router->is($target->node))
                : $targets;
            $hasComposedPool = $router instanceof Node
                && is_string($router->wireguard_ip)
                && $localTargets->isNotEmpty()
                && $remoteTargets->isNotEmpty()
                && ! $ingressSharesRouter;
            $hasRouterSite = $router instanceof Node
            && is_string($router->wireguard_ip)
            && $remoteTargets->isNotEmpty()
            && $localTargets->isEmpty()
            && ! $ingressSharesRouter;

            foreach ($targets as $target) {
                if ($hasPublicIngress && $ingress->is($target->node) && $ingressSharesRouter) {
                    $sites->push($this->composedPublicSite($target, $route, $ingress));

                    continue;
                }

                if ($hasComposedPool && $router->is($target->node)) {
                    continue;
                }

                $sites->push($this->appInstanceSite($target, $route));
            }

            if ($hasComposedPool) {
                $sites->push($this->composedPoolSite(
                    array_values($localTargets->all()),
                    array_values($remoteTargets->all()),
                    $route,
                    $router,
                ));
            }

            if ($hasRouterSite) {
                $sites->push($this->routerSite(array_values($remoteTargets->all()), $route, $router));
            }

            if (
                $targets->isEmpty()
                && $router instanceof Node
                && in_array($route->status, [RouteStatus::Active, RouteStatus::Activating], true)
                && ! AppInstanceRemovalMember::query()
                    ->where('route_id', $route->id)
                    ->whereNull('row_deleted_at')
                    ->exists()
            ) {
                $sites->push($this->unavailableRouteSite($route, $router));
            }

            if ($hasPublicIngress && ! $ingressSharesRouter) {
                $sites->push($this->ingressSite($route, $ingress, $router));
            }

            if (
                $hasPublicIngress
                && $ingressSharesRouter
                && ! $targets->contains(static fn (AppInstance $target): bool => $ingress->is($target->node))
            ) {
                $sites->push($this->publicRouterSite(array_values($targets->all()), $route, $ingress));
            }

            if (
                $pendingRoute instanceof Route
                && $route->is($pendingRoute)
                && $unavailableInstance instanceof AppInstance
                && $targets->isEmpty()
            ) {
                $sites->push($this->unavailableSite($unavailableInstance, $route, $router));
            }

        }

        if (
            $additionalRoute instanceof Route
            && $sites->every(static fn (AppDevSite $site): bool => $site->domain !== $additionalRoute->domain)
        ) {
            $this->appendDomainChangeSites($sites, $additionalRoute, $routerOverrides);
        }

        if ($node instanceof Node) {
            return $sites->where('nodeId', $node->id)->values();
        }

        return $sites->values();
    }

    /**
     * @param  Collection<int, AppDevSite>  $sites
     * @param  array<int, int>  $routerOverrides
     */
    private function appendDomainChangeSites(Collection $sites, Route $route, array $routerOverrides = []): void
    {
        $route->loadMissing([
            'targets.appInstance.app',
            'targets.appInstance.node',
            'cluster.routerAssignment.node',
        ]);
        $targets = $route
            ->targets
            ->map(static fn ($targetRow) => $targetRow->appInstance)
            ->filter(static fn ($target): bool => $target instanceof AppInstance)
            ->values();
        $router = $this->routerFor($route, $routerOverrides);

        foreach ($targets as $target) {
            $sites->push($this->appInstanceSite($target, $route, domainChange: true));
        }

        if (
            $router instanceof Node
            && $targets->isNotEmpty()
            && ! $targets->contains(static fn (AppInstance $target): bool => $router->is($target->node))
        ) {
            $sites->push($this->routerSite(
                array_values($targets->all()),
                $route,
                $router,
                domainChange: true,
            ));
        }
    }

    /**
     * @param  array<int, int>  $routerOverrides
     */
    private function routerFor(Route $route, array $routerOverrides): ?Node
    {
        $clusterId = $route->cluster_id;

        if (is_int($clusterId) && array_key_exists($clusterId, $routerOverrides)) {
            $router = Node::query()->find($routerOverrides[$clusterId]);

            return $router instanceof Node ? $router : null;
        }

        return $route->cluster?->routerAssignment?->node;
    }

    private function appInstanceSite(
        AppInstance $instance,
        Route $route,
        bool $domainChange = false,
    ): AppDevSite {
        $checkoutPath = $instance->usesProductionReleaseLayout()
            ? "{$instance->production_home}/current"
            : $instance->checkout_path;

        return new AppDevSite(
            nodeId: $instance->node_id,
            nodeAddress: $instance->node->wireguard_ip ?? '',
            scope: "app-instance-{$instance->id}",
            checkoutPath: $checkoutPath,
            documentRoot: $instance->root ?? $instance->app->root ?? '',
            phpVersion: $instance->selected_php_version,
            domain: $route->domain,
            environment: $instance->environment,
            productionUser: $instance->production_user,
            productionHome: $instance->production_home,
            appSlug: $instance->app->slug,
            certificateScope: $domainChange ? "app-instance-{$instance->id}-hostname-change" : null,
            productionPhpSocket: $instance->production_php_socket,
            vitePort: $instance->vite_port,
            agentationPort: $instance->agentation_port,
        );
    }

    /** @param list<AppInstance> $instances */
    private function routerSite(
        array $instances,
        Route $route,
        Node $router,
        bool $domainChange = false,
    ): AppDevSite {
        $addresses = collect($instances)
            ->map(static fn (AppInstance $instance): ?string => is_string($instance->node->lan_ip)
                && $instance->node->lan_ip !== ''
                    ? $instance->node->lan_ip
                    : $instance->node->wireguard_ip)
            ->filter(static fn (?string $address): bool => is_string($address) && $address !== '')
            ->values()
            ->all();

        /** @var list<string> $addresses */

        return new AppDevSite(
            nodeId: $router->id,
            nodeAddress: $router->wireguard_ip ?? '',
            scope: "route-{$route->id}-router",
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: $route->domain,
            upstreamAddresses: $addresses,
            certificateScope: $domainChange ? "route-{$route->id}-router-hostname-change" : null,
        );
    }

    /**
     * @param  list<AppInstance>  $local
     * @param  list<AppInstance>  $remote
     */
    private function composedPoolSite(array $local, array $remote, Route $route, Node $router): AppDevSite
    {
        $addresses = collect($remote)
            ->map(static fn (AppInstance $instance): ?string => is_string($instance->node->lan_ip)
                && $instance->node->lan_ip !== ''
                    ? $instance->node->lan_ip
                    : $instance->node->wireguard_ip)
            ->filter(static fn (?string $address): bool => is_string($address) && $address !== '')
            ->values()
            ->all();
        $localInstance = $local[0];

        /** @var list<string> $addresses */

        return new AppDevSite(
            nodeId: $router->id,
            nodeAddress: $router->wireguard_ip ?? '',
            scope: "route-{$route->id}-router",
            checkoutPath: $localInstance->usesProductionReleaseLayout()
                ? "{$localInstance->production_home}/current"
                : ($localInstance->checkout_path ?? ''),
            documentRoot: $localInstance->root ?? $localInstance->app->root ?? '',
            phpVersion: $localInstance->selected_php_version,
            domain: $route->domain,
            upstreamAddresses: $addresses,
            environment: $localInstance->environment,
            productionUser: $localInstance->production_user,
            productionHome: $localInstance->production_home,
            appSlug: $localInstance->app->slug,
            productionPhpSocket: $localInstance->production_php_socket,
            localUnixUpstream: 'unix//run/orbit/route-'.$route->id.'-local.sock',
        );
    }

    private function unavailableRouteSite(Route $route, Node $router): AppDevSite
    {
        return new AppDevSite(
            nodeId: $router->id,
            nodeAddress: $router->wireguard_ip ?? '',
            scope: "route-{$route->id}-router",
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: $route->domain,
            unavailable: true,
        );
    }

    private function unavailableSite(AppInstance $instance, Route $route, ?Node $router): AppDevSite
    {
        $usesRouterProjection = $router instanceof Node && ! $router->is($instance->node);
        $node = $usesRouterProjection ? $router : $instance->node;
        $scope = $usesRouterProjection
            ? "route-{$route->id}-router"
            : "app-instance-{$instance->id}";

        return new AppDevSite(
            nodeId: $node->id,
            nodeAddress: $node->wireguard_ip ?? '',
            scope: $scope,
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: $route->domain,
            unavailable: true,
        );
    }

    private function publishesIngress(Route $route): bool
    {
        return $this->eligibility->publicEdgeIsLive($route);
    }

    private function composedPublicSite(AppInstance $instance, Route $route, Node $ingress): AppDevSite
    {
        $site = $this->appInstanceSite($instance, $route);

        return new AppDevSite(
            nodeId: $ingress->id,
            nodeAddress: $ingress->wireguard_ip ?? '',
            scope: "route-{$route->id}-ingress",
            checkoutPath: $site->checkoutPath,
            documentRoot: $site->documentRoot,
            phpVersion: $site->phpVersion,
            domain: $route->domain,
            environment: $site->environment,
            productionUser: $site->productionUser,
            productionHome: $site->productionHome,
            appSlug: $site->appSlug,
            certificateScope: "route-{$route->id}-ingress",
            productionPhpSocket: $site->productionPhpSocket,
            publicListener: true,
            preserveForwardedIdentity: true,
        );
    }

    /** @param list<AppInstance> $instances */
    private function publicRouterSite(array $instances, Route $route, Node $ingress): AppDevSite
    {
        $router = $this->routerSite($instances, $route, $ingress);

        return new AppDevSite(
            nodeId: $ingress->id,
            nodeAddress: $ingress->wireguard_ip ?? '',
            scope: "route-{$route->id}-ingress",
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: $route->domain,
            upstreamAddresses: $router->proxyAddresses(),
            certificateScope: "route-{$route->id}-ingress",
            publicListener: true,
            preserveForwardedIdentity: true,
        );
    }

    private function customProxySite(Route $route): ?AppDevSite
    {
        $route->loadMissing(['node', 'customProxy']);
        $node = $route->node;
        $proxy = $route->customProxy;

        if (
            ! $node instanceof Node
            || $proxy === null
            || ! is_string($node->wireguard_ip)
            || $node->wireguard_ip === ''
        ) {
            return null;
        }

        return new AppDevSite(
            nodeId: $node->id,
            nodeAddress: $node->wireguard_ip,
            scope: "route-{$route->id}",
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: $route->domain,
            certificateScope: "route-{$route->id}",
            localHttpUpstream: CustomProxyUpstream::parse($proxy->upstream)->authority(),
        );
    }

    /**
     * A tracking host has no workload: its Router proxies Plausible's script and event paths to the
     * analytics role and the Ingress forwards the whole host to that Router. A Router that is also
     * the Ingress serves the public listener itself. The Router site is first, so private DNS
     * answers with the Router.
     *
     * @param  array<int, int>  $routerOverrides
     * @return list<AppDevSite>
     */
    private function analyticsTrackingSites(Route $route, ?Route $pendingRoute, array $routerOverrides): array
    {
        $served = in_array($route->status, [RouteStatus::Active, RouteStatus::Activating], true)
            || ($pendingRoute instanceof Route && $route->is($pendingRoute));
        // A cluster-scoped host is served by the cluster's Router; a node-scoped one by its own Node.
        $router = $route->cluster_id === null ? $route->node : $this->routerFor($route, $routerOverrides);
        $upstream = AnalyticsTrackingUpstream::current();

        if (
            ! $served
            || $upstream === null
            || ! $router instanceof Node
            || ! is_string($router->wireguard_ip)
            || $router->wireguard_ip === ''
        ) {
            return [];
        }

        $ingress = $route->cluster !== null && $this->publishesIngress($route)
            ? $this->eligibility->activeIngress($route->cluster)
            : null;

        if ($ingress instanceof Node && $ingress->is($router)) {
            return [new AppDevSite(
                nodeId: $ingress->id,
                nodeAddress: $router->wireguard_ip,
                scope: "route-{$route->id}-ingress",
                checkoutPath: '',
                documentRoot: '',
                phpVersion: null,
                domain: $route->domain,
                certificateScope: "route-{$route->id}-ingress",
                publicListener: true,
                analyticsUpstream: $upstream,
            )];
        }

        $trusted = $ingress instanceof Node
            ? array_values(array_filter(
                [$ingress->lan_ip, $ingress->wireguard_ip],
                static fn (?string $address): bool => is_string($address) && $address !== '',
            ))
            : [];
        $sites = [new AppDevSite(
            nodeId: $router->id,
            nodeAddress: $router->wireguard_ip,
            scope: "route-{$route->id}-router",
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: $route->domain,
            analyticsUpstream: $upstream,
            analyticsTrustedProxies: $trusted,
        )];

        if ($ingress instanceof Node) {
            $sites[] = $this->ingressSite($route, $ingress, $router);
        }

        return $sites;
    }

    private function ingressSite(Route $route, Node $ingress, ?Node $router): AppDevSite
    {
        $artifact = $this->ingressSites->forRoute($route);
        $upstream = $router instanceof Node && ! $router->is($ingress)
            ? [$artifact->routerUpstream]
            : [];

        return new AppDevSite(
            nodeId: $ingress->id,
            nodeAddress: $ingress->wireguard_ip ?? '',
            scope: "route-{$route->id}-ingress",
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: $route->domain,
            upstreamAddresses: $upstream,
            certificateScope: $artifact->certificateScope,
            publicListener: true,
            preserveForwardedIdentity: true,
        );
    }
}
