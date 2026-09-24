<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\Analytics\AnalyticsTrackingUpstream;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Routes\CustomProxyUpstream;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteCertificateStaging;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteReplacementStep;
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
        private ClusterRouterTransition $routerTransitions = new ClusterRouterTransition,
    ) {}

    /**
     * Every site on the Node, read from stored state only, so any converge on the Node renders the
     * same sites.
     *
     * @return Collection<int, AppDevSite>
     */
    public function forNode(Node $node): Collection
    {
        return $this->sites($node);
    }

    /** @return Collection<int, AppDevSite> */
    public function all(): Collection
    {
        return $this->sites(null);
    }

    /** @return Collection<int, AppDevSite> */
    private function sites(?Node $node): Collection
    {
        /** @var Collection<int, AppDevSite> $sites */
        $sites = collect();
        $secondRouters = $this->routerTransitions->secondRouters();

        $routeQuery = Route::query()
            ->with([
                'targets.appInstance.app',
                'targets.appInstance.node',
                'cluster.routerAssignment.node',
                'cluster.ingressAssignment.node',
                'transitionCluster.routerAssignment.node',
                'transitionNode',
                'customProxy',
                'analyticsTracking',
                'node',
            ])
            ->where(static function (Builder $query): void {
                // A pending domain change replacement renders from its stored steps. A failed
                // replacement serves nothing: its rollback marks it failed before it withdraws the
                // candidate sites and then removes their certificates.
                $query->where('sites_published', true)
                    ->orWhere(static function (Builder $query): void {
                        $query
                            ->where('status', RouteStatus::Pending->value)
                            ->whereNotNull('replaces_route_id');
                    });
            });

        if ($node instanceof Node) {
            $nodeId = $node->id;
            $secondRouterClusterIds = array_keys(array_filter(
                $secondRouters,
                static fn (array $routers): bool => collect($routers)->contains(
                    static fn (Node $router): bool => $router->id === $nodeId,
                ),
            ));
            $routeQuery->where(static function (Builder $query) use ($nodeId, $secondRouterClusterIds): void {
                $query
                    ->where('routes.node_id', $nodeId)
                    ->orWhere('routes.transition_node_id', $nodeId)
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
                    )
                    ->orWhereHas(
                        'transitionCluster.routerAssignment',
                        static fn (Builder $query): Builder => $query->where('node_id', $nodeId),
                    )
                    ->orWhereExists(static fn ($members) => $members
                        ->selectRaw('1')
                        ->from('app_instance_removal_members')
                        ->whereColumn('app_instance_removal_members.route_id', 'routes.id')
                        ->where('app_instance_removal_members.node_id', $nodeId)
                        ->whereNull('app_instance_removal_members.row_deleted_at'));

                if ($secondRouterClusterIds !== []) {
                    $query->orWhereIn('routes.cluster_id', $secondRouterClusterIds);
                }
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

        $routes = $routeQuery->orderBy('id')->get();
        /** @var Collection<int, Route> $routes */
        foreach ($routes as $route) {
            if ($route->kind === RouteKind::CustomProxy) {
                $site = $this->customProxySite($route);

                if ($site instanceof AppDevSite) {
                    $sites->push($site);
                }

                continue;
            }

            $router = $route->cluster?->routerAssignment?->node;
            $clusterSecondRouters = is_int($route->cluster_id) ? ($secondRouters[$route->cluster_id] ?? []) : [];

            if ($route->kind === RouteKind::AnalyticsTracking) {
                $sites->push(...$this->analyticsTrackingSites($route, $route->cluster_id === null ? $route->node : $router));
                $sites->push(...$this->analyticsTrackingTransitionSites($route));

                foreach ($clusterSecondRouters as $secondRouter) {
                    $sites->push(...$this->routerScopeSites(
                        $route,
                        $secondRouter,
                        $this->analyticsTrackingSites($route, $secondRouter, includeIngress: false),
                    ));
                }

                continue;
            }

            $sites->push(...$this->projectRouteSites(
                $route,
                $router,
                stagesWorkload: RouteCertificateStaging::workload($route),
                stagesRouter: RouteCertificateStaging::router($route),
            ));

            foreach ($clusterSecondRouters as $secondRouter) {
                $sites->push(...$this->routerScopeSites($route, $secondRouter, $this->projectRouteSites(
                    $route,
                    $secondRouter,
                    stagesWorkload: RouteCertificateStaging::workload($route),
                    stagesRouter: RouteCertificateStaging::router($route),
                )));
            }

            $sites->push(...$this->secondPlacementSites($route));

            $unavailable = $this->unavailableSite($route, $router);

            if ($unavailable instanceof AppDevSite) {
                $sites->push($unavailable);
            }
        }

        $sites = $this->withoutDuplicateSecondarySites($sites);

        if ($node instanceof Node) {
            return $sites->where('nodeId', $node->id)->values();
        }

        return $sites->values();
    }

    /**
     * The sites a Project Route renders with one Router: workload, Router, composed pool, public
     * Ingress, and the unavailable Router answer of a targetless Route.
     *
     * @return list<AppDevSite>
     */
    private function projectRouteSites(Route $route, ?Node $router, bool $stagesWorkload, bool $stagesRouter): array
    {
        $sites = [];
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
        // A pending replacement answers from the staging certificates its domain change issues. Each
        // site appears only once the step that writes its certificate has completed.
        $gatesOnSteps = $route->status === RouteStatus::Pending && $route->replaces_route_id !== null;

        if ($gatesOnSteps && ! RouteCertificateStaging::reached($route, RouteReplacementStep::WorkloadCertificate)) {
            return [];
        }

        if ($gatesOnSteps && ! RouteCertificateStaging::reached($route, RouteReplacementStep::RouterCertificate)) {
            $router = null;
        }

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
                $sites[] = $this->composedPublicSite($target, $route, $ingress);

                continue;
            }

            if ($hasComposedPool && $router->is($target->node)) {
                continue;
            }

            $sites[] = $this->appInstanceSite($target, $route, domainChange: $stagesWorkload);
        }

        if ($hasComposedPool) {
            $sites[] = $this->composedPoolSite(
                array_values($localTargets->all()),
                array_values($remoteTargets->all()),
                $route,
                $router,
                domainChange: $stagesRouter,
            );
        }

        if ($hasRouterSite) {
            $sites[] = $this->routerSite(
                array_values($remoteTargets->all()),
                $route,
                $router,
                domainChange: $stagesRouter,
            );
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
            $sites[] = $this->unavailableRouteSite($route, $router);
        }

        if ($hasPublicIngress && ! $ingressSharesRouter) {
            $sites[] = $this->ingressSite($route, $ingress, $router);
        }

        if (
            $hasPublicIngress
            && $ingressSharesRouter
            && ! $targets->contains(static fn (AppInstance $target): bool => $ingress->is($target->node))
        ) {
            $sites[] = $this->publicRouterSite(array_values($targets->all()), $route, $ingress);
        }

        return $sites;
    }

    /**
     * A second Router serves only the Route's Router scope sites. The Ingress upstream and every
     * workload site follow the active Router.
     *
     * @param  list<AppDevSite>  $sites
     * @return list<AppDevSite>
     */
    private function routerScopeSites(Route $route, Node $router, array $sites): array
    {
        return array_values(array_map(
            static fn (AppDevSite $site): AppDevSite => $site->asSecondary(),
            array_filter(
                $sites,
                static fn (AppDevSite $site): bool => $site->nodeId === $router->id
                    && $site->scope === "route-{$route->id}-router",
            ),
        ));
    }

    /**
     * The second placement of a placement change: the candidate before `database-cutover`, which
     * answers from the staging Router scope once its certificate exists, and the old placement
     * after it, which keeps its live scopes until cleanup.
     *
     * @return list<AppDevSite>
     */
    private function secondPlacementSites(Route $route): array
    {
        $candidate = RouteCertificateStaging::placementCandidate($route);

        if (! $candidate && ! RouteCertificateStaging::placementCutOver($route)) {
            return [];
        }

        if ($candidate && ! RouteCertificateStaging::reached($route, RouteReplacementStep::WorkloadCertificate)) {
            return [];
        }

        $second = $route->replicate();
        $second->id = $route->id;
        $second->exists = true;
        $second->node_id = $route->transition_node_id;
        $second->cluster_id = $route->transition_cluster_id;
        $second->transition_node_id = null;
        $second->transition_cluster_id = null;
        $second->setRelation('targets', $route->targets);
        $second->setRelation('cluster', $route->transitionCluster);
        $router = $route->transitionCluster?->routerAssignment?->node;

        if ($candidate && ! RouteCertificateStaging::reached($route, RouteReplacementStep::RouterCertificate)) {
            $router = null;
        }

        $sites = $this->projectRouteSites($second, $router, stagesWorkload: $candidate, stagesRouter: $candidate);

        return array_values(array_map(
            static fn (AppDevSite $site): AppDevSite => $site->asSecondary(),
            array_filter($sites, static fn (AppDevSite $site): bool => ! $site->publicListener),
        ));
    }

    /**
     * The unavailable answer of an Instance removal: its targets are gone, the Route still has its
     * publication record, and an open development removal member names the departing Instance.
     */
    private function unavailableSite(Route $route, ?Node $router): ?AppDevSite
    {
        if ($route->targets->isNotEmpty()) {
            return null;
        }

        $member = AppInstanceRemovalMember::query()
            ->where('route_id', $route->id)
            ->where('environment', 'development')
            ->whereNull('row_deleted_at')
            ->whereNull('route_cleared_at')
            ->orderBy('id')
            ->first();
        $instance = $member instanceof AppInstanceRemovalMember
            ? AppInstance::query()->with('node')->find($member->app_instance_id)
            : null;

        if (! $instance instanceof AppInstance) {
            return null;
        }

        $usesRouterProjection = $router instanceof Node && ! $router->is($instance->node);
        $node = $usesRouterProjection ? $router : $instance->node;

        return new AppDevSite(
            nodeId: $node->id,
            nodeAddress: $node->wireguard_ip ?? '',
            scope: $usesRouterProjection ? "route-{$route->id}-router" : "app-instance-{$instance->id}",
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: $route->domain,
            unavailable: true,
        );
    }

    /**
     * A second placement or second Router site that renders an address the current site already
     * serves on that Node is skipped, so the Node keeps one site block for the address.
     *
     * @param  Collection<int, AppDevSite>  $sites
     * @return Collection<int, AppDevSite>
     */
    private function withoutDuplicateSecondarySites(Collection $sites): Collection
    {
        $current = $sites
            ->reject(static fn (AppDevSite $site): bool => $site->secondary)
            ->map(static fn (AppDevSite $site): string => $site->addressKey())
            ->flip();
        $seen = [];

        return $sites
            ->filter(static function (AppDevSite $site) use ($current, &$seen): bool {
                if (! $site->secondary) {
                    return true;
                }

                $key = $site->addressKey();

                if ($current->has($key) || isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            })
            ->values();
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
    private function composedPoolSite(
        array $local,
        array $remote,
        Route $route,
        Node $router,
        bool $domainChange = false,
    ): AppDevSite {
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
            certificateScope: $domainChange ? "route-{$route->id}-router-hostname-change" : null,
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
     * @return list<AppDevSite>
     */
    private function analyticsTrackingSites(Route $route, ?Node $router, bool $includeIngress = true): array
    {
        $upstream = AnalyticsTrackingUpstream::current(includeConverging: true);

        // A retiring tracking host is on its way to removal and serves nothing, as its public edge
        // was withdrawn first.
        if (
            ! $route->sites_published
            || $route->status === RouteStatus::Retiring
            || $upstream === null
            || ! $router instanceof Node
            || ! is_string($router->wireguard_ip)
            || $router->wireguard_ip === ''
        ) {
            return [];
        }

        $ingress = $includeIngress && $route->cluster !== null && $this->publishesIngress($route)
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

    /**
     * A tracking host that moves with its Instance Route keeps serving its second placement until
     * the move stores `cleanup` to withdraw it. Private DNS answers only with the current placement.
     *
     * @return list<AppDevSite>
     */
    private function analyticsTrackingTransitionSites(Route $route): array
    {
        if (! $route->hasPlacementTransition() || $route->replacement_step === RouteReplacementStep::Cleanup) {
            return [];
        }

        $host = $route->transition_cluster_id === null
            ? $route->transitionNode
            : $route->transitionCluster?->routerAssignment?->node;

        return array_map(
            static fn (AppDevSite $site): AppDevSite => $site->asSecondary(),
            $this->analyticsTrackingSites($route, $host, includeIngress: false),
        );
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
