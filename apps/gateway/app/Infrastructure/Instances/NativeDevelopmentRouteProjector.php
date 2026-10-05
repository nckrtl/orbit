<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppDev\ViteEnvironmentProjection;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\Transfer\InstanceTransferRouteProjector;
use App\Domain\Processes\ProcessEnvironmentProjection;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RoutePublication;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppDev\RemoteAppDevRouteFirewallManager;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteTarget;

final readonly class NativeDevelopmentRouteProjector implements DevelopmentRouteProjector, InstanceTransferRouteProjector, RouteDomainProjector
{
    public function __construct(
        private RemoteAppDevPhpFpmManager $php,
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevCaddyManager $caddy,
        private DnsmasqPrivateDnsManager $dns,
        private DevelopmentSshExecutor $ssh,
        private ?PublicRouteEdgeProjector $publicEdge = null,
    ) {}

    public function converge(Instance $instance, Route $route): void
    {
        $instance->loadMissing('node');
        $route->loadMissing('cluster.routerAssignment.node');
        // Creation stores the publication record once the certificate its sites name exists, and
        // before its first render.
        $this->certificates->upgradeInstanceApp($instance, $route);
        $this->certificates->convergeInstance($instance, $route);
        $route->publishSites();

        $this->ssh->execute(
            $instance->node,
            new DevelopmentCaddyAccessCommand()->command(
                new DevelopmentSiteRepository()->forNode($instance->node),
            ),
            step: 'source-access',
            errorCode: 'app-dev.source_access_failed',
        );
        $this->php->converge($instance->node);
        $this->caddy->build($instance->node);
        $app = $instance->appConfiguration($route->app)['name'];
        if ($instance->processes()->where('app', $app)->where('runtime_config->preset', 'vp-dev')->exists()) {
            app(ViteEnvironmentProjection::class)->stageEnvironment($instance, $app);
        }
        app(ProcessEnvironmentProjection::class)->project($instance, 0, $app);
        $this->certificates->retireLegacyInstance($instance);

        $router = $route->cluster?->routerAssignment?->node;

        if ($route->cluster_id !== null && ! $router instanceof Node) {
            throw new RuntimeConvergenceException(
                step: 'router',
                errorCode: 'cluster.router_required',
                message: 'The Cluster Route requires an active Router.',
            );
        }

        if ($router instanceof Node && $router->is($instance->node)) {
            $this->dns->converge();
            $instance->recordAppRuntime($app, ['app_identity_ready' => true]);

            return;
        }

        if ($router instanceof Node) {
            $this->certificates->convergeRouteRouter($route, $router);
            $this->convergeLanFirewall($instance, $route, $router);
            $this->verifyWorkloadLeaf($instance, $route, $router);
            $this->caddy->build($router);
        }

        // DNS is deliberately last. A failed earlier projection is never reachable by name.
        $this->dns->converge();
        $instance->recordAppRuntime($app, ['app_identity_ready' => true]);
    }

    public function prepareWorkloadCertificate(Instance $instance, Route $current, Route $candidate): void
    {
        $this->certificates->convergeInstanceHostnameChange($instance, $candidate->domain, $candidate->app);
    }

    public function retireSource(InstanceTransfer $transfer): void
    {
        app(DevelopmentProjectionOperationLock::class)->run(fn () => $this->retireSourceOwned($transfer));
    }

    private function retireSourceOwned(InstanceTransfer $transfer): void
    {
        $transfer->load(['sourceNode', 'instance.node']);
        $source = $transfer->sourceNode;
        $sourceRouter = Node::query()->find($transfer->source_router_node_id);
        if (! $sourceRouter instanceof Node || $transfer->cutover_at === null) {
            throw new RuntimeConvergenceException(
                step: 'source-cleanup',
                errorCode: 'instance.transfer_source_router_unknown',
                message: 'The original Router must be recorded before retiring source projections.',
            );
        }
        $destinationRoute = Route::query()->with('cluster.routerAssignment.node')
            ->findOrFail($transfer->destination_route_id ?? $transfer->source_route_id);
        $destinationRouter = $destinationRoute->cluster?->routerAssignment?->node;
        $nodes = collect([$source, $sourceRouter, $transfer->instance->node]);
        if ($destinationRouter instanceof Node) {
            $nodes->push($destinationRouter);
        }

        foreach ($nodes->unique('id') as $node) {
            $this->caddy->build($node);
        }
        $this->php->converge($source);
        $this->dns->converge();

        $sourceInstance = clone $transfer->instance;
        $sourceInstance->setRelation('node', $source);
        if (! $this->usesCertificate($source, "app-instance-{$sourceInstance->id}")) {
            $this->certificates->removeInstance($sourceInstance);
        }
        new RemoteAppDevRouteFirewallManager($this->ssh)->remove($source, $transfer->source_route_id);
        if (! $this->usesCertificate($sourceRouter, "route-{$transfer->source_route_id}-router")) {
            $oldRoute = new Route;
            $oldRoute->id = $transfer->source_route_id;
            $this->certificates->removeRouteRouter($oldRoute, $sourceRouter);
        }
    }

    private function usesCertificate(Node $node, string $scope): bool
    {
        return new DevelopmentSiteRepository()->forNode($node)->contains(
            static fn (DevelopmentSite $site): bool => $site->loadsCertificate($scope),
        );
    }

    public function prepareWorkloadCaddy(Instance $instance, Route $current, Route $candidate): void
    {
        $instance->loadMissing('node');
        $this->caddy->build($instance->node);
    }

    public function prepareRouterCertificate(Instance $instance, Route $current, Route $candidate): void
    {
        $router = $this->router($instance, $candidate);

        if ($router instanceof Node) {
            $this->certificates->convergeRouteRouterHostnameChange($candidate, $router);
        }
    }

    public function prepareFirewallPolicy(Instance $instance, Route $candidate): void
    {
        $router = $this->router($instance, $candidate);

        if ($router instanceof Node) {
            $this->convergeLanFirewall($instance, $candidate, $router);
        }
    }

    public function verifyWorkload(Instance $instance, Route $candidate): void
    {
        $router = $this->router($instance, $candidate);

        if ($router instanceof Node) {
            $this->verifyWorkloadLeaf($instance, $candidate, $router);
        }
    }

    public function prepareRouterCaddy(Instance $instance, Route $current, Route $candidate): void
    {
        $router = $this->router($instance, $candidate);

        if ($router instanceof Node) {
            $this->caddy->build($router);
        }
    }

    public function prepareIngressCertificate(Route $candidate): void
    {
        if ($candidate->publication === RoutePublication::Public) {
            $this->publicEdge()->prepareIngressCertificate($candidate);
        }
    }

    public function prepareIngressFirewall(Route $candidate): void
    {
        if ($candidate->publication === RoutePublication::Public) {
            $this->publicEdge()->prepareIngressFirewall($candidate);
        }
    }

    public function verifyPublicEdge(Route $candidate): void
    {
        if ($candidate->publication === RoutePublication::Public) {
            $this->publicEdge()->verifyPublicEdge($candidate);
        }
    }

    public function activatePublicHandler(Route $candidate): void
    {
        if ($candidate->publication === RoutePublication::Public) {
            $this->publicEdge()->activatePublicHandler($candidate);
        }
    }

    public function rollbackPublicEdge(Route $route): void
    {
        if ($route->publication === RoutePublication::Public) {
            $this->publicEdge()->rollbackPublicEdge($route);
        }
    }

    public function publishDns(Route $current, Route $candidate): void
    {
        $this->dns->converge();
    }

    /**
     * Cleanup runs after cutover, so `$route` is the Route the Node now serves. Both the live
     * certificate and the staging scopes are named after it: a certificate issued for the retiring
     * domain would leave the served host without a matching leaf, and Caddy would fall back to
     * automatic HTTPS for a private Orbit domain. The live certificates exist before the Route's
     * stored step makes its sites name them.
     */
    public function prepareCleanup(Instance $instance, Route $route): void
    {
        $instance->loadMissing('node');
        $route->loadMissing('cluster.routerAssignment.node');
        $this->certificates->convergeInstance($instance, $route);
        $router = $this->routeRouter($route);

        if ($router instanceof Node) {
            $this->certificates->convergeRouteRouter($route, $router);
        }
    }

    /**
     * The stored step now renders the live scopes and no second placement, so each build drops the
     * staging and old sites before their certificates are removed.
     */
    public function cleanup(Instance $instance, Route $route): void
    {
        $instance->loadMissing('node');
        $route->loadMissing(['cluster.routerAssignment.node', 'transitionCluster.routerAssignment.node']);
        $router = $this->routeRouter($route);
        $built = [$instance->node];
        $this->caddy->build($instance->node);

        if ($router instanceof Node && ! $router->is($instance->node)) {
            $this->caddy->build($router);
            $built[] = $router;
        }

        $instance->unsetRelation('routes');
        app(ProcessEnvironmentProjection::class)->project($instance, 0, $instance->appConfiguration($route->app)['name']);
        $this->certificates->removeHostnameChange($instance, $route);
        $this->removeOldPlacementRouterCertificate($route, $built);
        $this->removeRetiringRouterCertificate($route, $built);
        $this->dns->converge();
    }

    /**
     * A placement change leaves the Route's live Router leaf on the Router of its old placement.
     * That Router is built without the old placement before its leaf is removed.
     *
     * @param  list<Node>  $built
     */
    private function removeOldPlacementRouterCertificate(Route $route, array $built): void
    {
        $oldRouter = $route->transitionCluster?->routerAssignment?->node;

        if (! $route->hasPlacementTransition() || ! $oldRouter instanceof Node) {
            return;
        }

        if (! collect($built)->contains(static fn (Node $node): bool => $node->is($oldRouter))) {
            $this->caddy->build($oldRouter);
        }

        if (! $this->usesCertificate($oldRouter, "route-{$route->id}-router")) {
            $this->certificates->removeRouteRouter($route, $oldRouter);
        }
    }

    /**
     * The Router that serves a Router or composed pool site for the Route. A composed pool on a
     * Router that also holds a target uses the Route's Router certificate, whichever target the
     * cleanup handles first.
     */
    private function routeRouter(Route $route): ?Node
    {
        $route->loadMissing(['cluster.routerAssignment.node', 'targets.instance']);
        $router = $route->cluster?->routerAssignment?->node;

        if (! $router instanceof Node) {
            return null;
        }

        return $route->targets->contains(
            static fn (RouteTarget $target): bool => $target->instance->node_id !== $router->id,
        ) ? $router : null;
    }

    /**
     * The retiring Route stops being served at cutover. Its Router leaf lives on the Router that
     * served it, which is not the new Router when the change also moved the Route to another
     * Cluster. That Router is republished without the retiring site before its leaf is removed.
     *
     * @param  list<Node>  $published
     */
    private function removeRetiringRouterCertificate(Route $route, array $published): void
    {
        if ($route->replaces_route_id === null) {
            return;
        }

        $retiring = Route::query()->with('cluster.routerAssignment.node')->find($route->replaces_route_id);
        $router = $retiring?->cluster?->routerAssignment?->node;

        if (! $retiring instanceof Route || ! $router instanceof Node) {
            return;
        }

        if (! collect($published)->contains(static fn (Node $node): bool => $node->is($router))) {
            $this->caddy->build($router);
        }

        if (! $this->usesCertificate($router, "route-{$retiring->id}-router")) {
            $this->certificates->removeRouteRouter($retiring, $router);
        }
    }

    public function rollbackDns(Route $route): void
    {
        $this->dns->converge();
    }

    public function rollbackCaddy(Instance $instance, Route $route): void
    {
        $instance->unsetRelation('routes');
        app(ProcessEnvironmentProjection::class)->project($instance, 0, $instance->appConfiguration($route->app)['name']);
        $instance->loadMissing('node');
        $this->caddy->build($instance->node);
        $router = $this->router($instance, $route);

        if ($router instanceof Node) {
            $this->caddy->build($router);
        }
    }

    public function rollbackCertificates(Instance $instance, Route $route): void
    {
        $this->certificates->removeHostnameChange($instance, $route);
    }

    private function publicEdge(): PublicRouteEdgeProjector
    {
        return $this->publicEdge ?? app(PublicRouteEdgeProjector::class);
    }

    private function router(Instance $instance, Route $route): ?Node
    {
        $instance->loadMissing('node');
        $route->loadMissing('cluster.routerAssignment.node');
        $router = $route->cluster?->routerAssignment?->node;

        return $router instanceof Node && ! $router->is($instance->node) ? $router : null;
    }

    private function convergeLanFirewall(Instance $instance, Route $route, Node $router): void
    {
        $workloadAddress = $instance->node->lan_ip;

        if (! is_string($workloadAddress) || $workloadAddress === '') {
            return;
        }

        $routerAddress = $router->lan_ip;

        if (! is_string($routerAddress) || $routerAddress === '') {
            throw new RuntimeConvergenceException(
                step: 'route-address',
                errorCode: 'route.lan_unreachable',
                message: 'The configured workload LAN requires a Router LAN address.',
            );
        }

        $this->ssh->execute(
            $instance->node,
            new RemoteCommand([
                'sudo',
                'ufw',
                'allow',
                'in',
                'proto',
                'tcp',
                'from',
                $routerAddress,
                'to',
                $workloadAddress,
                'port',
                '443',
                'comment',
                "orbit:route-{$route->id}-lan",
            ]),
            step: 'route-firewall',
            errorCode: 'app-dev.route_firewall_failed',
        );
    }

    private function verifyWorkloadLeaf(Instance $instance, Route $route, Node $router): void
    {
        $address = is_string($instance->node->lan_ip) && $instance->node->lan_ip !== ''
            ? $instance->node->lan_ip
            : $instance->node->wireguard_ip;

        if (! is_string($address) || $address === '') {
            throw new RuntimeConvergenceException(
                step: 'route-address',
                errorCode: 'route.workload_address_missing',
                message: 'The Route workload has no private address.',
            );
        }

        $this->ssh->execute(
            $router,
            new RemoteCommand(
                [
                    'timeout',
                    '10',
                    'openssl',
                    's_client',
                    '-connect',
                    "{$address}:443",
                    '-servername',
                    $route->domain,
                    '-verify_return_error',
                ],
                input: '',
            ),
            step: 'workload-certificate',
            errorCode: 'app-dev.workload_certificate_invalid',
            commandTimeout: 15,
        );
    }
}
