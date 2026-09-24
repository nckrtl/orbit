<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\DevelopmentRouteProjector;
use App\Domain\AppInstances\Transfer\AppInstanceTransferRouteProjector;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RoutePublication;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppDev\RemoteAppDevRouteFirewallManager;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use App\Models\AppInstanceTransfer;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteTarget;

final readonly class NativeDevelopmentRouteProjector implements AppInstanceTransferRouteProjector, DevelopmentRouteProjector, RouteDomainProjector
{
    public function __construct(
        private RemoteAppDevPhpFpmManager $php,
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevCaddyManager $caddy,
        private DnsmasqPrivateDnsManager $dns,
        private AppDevSshExecutor $ssh,
        private ?PublicRouteEdgeProjector $publicEdge = null,
    ) {}

    public function converge(AppInstance $appInstance, Route $route): void
    {
        $appInstance->loadMissing('node');
        $route->loadMissing('cluster.routerAssignment.node');

        $this->ssh->execute(
            $appInstance->node,
            new DevelopmentCaddyAccessCommand()->command(
                new AppDevSiteRepository()->forNode($appInstance->node, $route),
            ),
            step: 'source-access',
            errorCode: 'app-dev.source_access_failed',
        );
        $this->php->convergeRoute($appInstance->node, $route);
        $this->certificates->convergeAppInstance($appInstance, $route);
        $this->caddy->convergeRoute($appInstance->node, $route);

        $router = $route->cluster?->routerAssignment?->node;

        if ($route->cluster_id !== null && ! $router instanceof Node) {
            throw new RuntimeConvergenceException(
                step: 'router',
                errorCode: 'cluster.router_required',
                message: 'The Cluster Route requires an active Router.',
            );
        }

        if ($router instanceof Node && $router->is($appInstance->node)) {
            $this->dns->convergeRoute($route);

            return;
        }

        if ($router instanceof Node) {
            $this->certificates->convergeRouteRouter($route, $router);
            $this->convergeLanFirewall($appInstance, $route, $router);
            $this->verifyWorkloadLeaf($appInstance, $route, $router);
            $this->caddy->convergeRoute($router, $route);
        }

        // DNS is deliberately last. A failed earlier projection is never reachable by name.
        $this->dns->convergeRoute($route);
    }

    public function prepareWorkloadCertificate(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $this->certificates->convergeAppInstanceHostnameChange($appInstance, $candidate->domain);
    }

    public function retireSource(AppInstanceTransfer $transfer): void
    {
        app(DevelopmentProjectionOperationLock::class)->run(fn () => $this->retireSourceOwned($transfer));
    }

    private function retireSourceOwned(AppInstanceTransfer $transfer): void
    {
        $transfer->load(['sourceNode', 'appInstance.node']);
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
        $nodes = collect([$source, $sourceRouter, $transfer->appInstance->node]);
        if ($destinationRouter instanceof Node) {
            $nodes->push($destinationRouter);
        }

        foreach ($nodes->unique('id') as $node) {
            $this->caddy->converge($node);
        }
        $this->php->converge($source);
        $this->dns->converge();

        $sourceInstance = clone $transfer->appInstance;
        $sourceInstance->setRelation('node', $source);
        if (! $this->usesCertificate($source, "app-instance-{$sourceInstance->id}")) {
            $this->certificates->removeAppInstance($sourceInstance);
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
        return new AppDevSiteRepository()->forNode($node)->contains(
            static fn (AppDevSite $site): bool => ($site->certificateScope ?? $site->scope) === $scope,
        );
    }

    public function prepareWorkloadCaddy(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $appInstance->loadMissing('node');
        $this->caddy->convergeHostnameChange($appInstance->node, $candidate);
    }

    public function prepareRouterCertificate(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $router = $this->router($appInstance, $candidate);

        if ($router instanceof Node) {
            $this->certificates->convergeRouteRouterHostnameChange($candidate, $router);
        }
    }

    public function prepareFirewallPolicy(AppInstance $appInstance, Route $candidate): void
    {
        $router = $this->router($appInstance, $candidate);

        if ($router instanceof Node) {
            $this->convergeLanFirewall($appInstance, $candidate, $router);
        }
    }

    public function verifyWorkload(AppInstance $appInstance, Route $candidate): void
    {
        $router = $this->router($appInstance, $candidate);

        if ($router instanceof Node) {
            $this->verifyWorkloadLeaf($appInstance, $candidate, $router);
        }
    }

    public function prepareRouterCaddy(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $router = $this->router($appInstance, $candidate);

        if ($router instanceof Node) {
            $this->caddy->convergeHostnameChange($router, $candidate);
        }
    }

    public function prepareIngressCertificate(Route $candidate): void
    {
        if ($candidate->publication === RoutePublication::Public) {
            $this->publicEdge()->prepareIngressCertificate($candidate);
        }
    }

    public function stageIngressCaddy(Route $candidate): void
    {
        if ($candidate->publication === RoutePublication::Public) {
            $this->publicEdge()->stageIngressCaddy($candidate);
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
        $this->dns->convergeHostnameChange($candidate);
    }

    /**
     * Cleanup runs after cutover, so `$route` is the Route the Node now serves. Both the live
     * certificate and the staging scopes are named after it: a certificate issued for the retiring
     * domain would leave the served host without a matching leaf, and Caddy would fall back to
     * automatic HTTPS for a private Orbit domain.
     */
    public function cleanup(AppInstance $appInstance, Route $route): void
    {
        $appInstance->loadMissing('node');
        $route->loadMissing('cluster.routerAssignment.node');
        $this->certificates->convergeAppInstance($appInstance, $route);
        $router = $this->routeRouter($route);

        if ($router instanceof Node) {
            $this->certificates->convergeRouteRouter($route, $router);
        }

        $this->caddy->converge($appInstance->node);

        if ($router instanceof Node && ! $router->is($appInstance->node)) {
            $this->caddy->converge($router);
        }

        $this->certificates->removeHostnameChange($appInstance, $route);
        $this->removeRetiringRouterCertificate($route, [$appInstance->node, $router]);
        $this->dns->converge();
    }

    /**
     * The Router that serves a Router or composed pool site for the Route. A composed pool on a
     * Router that also holds a target uses the Route's Router certificate, whichever target the
     * cleanup handles first.
     */
    private function routeRouter(Route $route): ?Node
    {
        $route->loadMissing(['cluster.routerAssignment.node', 'targets.appInstance']);
        $router = $route->cluster?->routerAssignment?->node;

        if (! $router instanceof Node) {
            return null;
        }

        return $route->targets->contains(
            static fn (RouteTarget $target): bool => $target->appInstance->node_id !== $router->id,
        ) ? $router : null;
    }

    /**
     * The retiring Route stops being served at cutover. Its Router leaf lives on the Router that
     * served it, which is not the new Router when the change also moved the Route to another
     * Cluster. That Router is republished without the retiring site before its leaf is removed.
     *
     * @param  list<?Node>  $published
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

        if (! collect($published)->contains(static fn (?Node $node): bool => $node?->is($router) === true)) {
            $this->caddy->converge($router);
        }

        if (! $this->usesCertificate($router, "route-{$retiring->id}-router")) {
            $this->certificates->removeRouteRouter($retiring, $router);
        }
    }

    public function rollbackDns(Route $route): void
    {
        $this->dns->converge();
    }

    public function rollbackCaddy(AppInstance $appInstance, Route $route): void
    {
        $appInstance->loadMissing('node');
        $this->caddy->converge($appInstance->node);
        $router = $this->router($appInstance, $route);

        if ($router instanceof Node) {
            $this->caddy->converge($router);
        }
    }

    public function rollbackCertificates(AppInstance $appInstance, Route $route): void
    {
        $this->certificates->removeHostnameChange($appInstance, $route);
    }

    private function publicEdge(): PublicRouteEdgeProjector
    {
        return $this->publicEdge ?? app(PublicRouteEdgeProjector::class);
    }

    private function router(AppInstance $appInstance, Route $route): ?Node
    {
        $appInstance->loadMissing('node');
        $route->loadMissing('cluster.routerAssignment.node');
        $router = $route->cluster?->routerAssignment?->node;

        return $router instanceof Node && ! $router->is($appInstance->node) ? $router : null;
    }

    private function convergeLanFirewall(AppInstance $appInstance, Route $route, Node $router): void
    {
        $workloadAddress = $appInstance->node->lan_ip;

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
            $appInstance->node,
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

    private function verifyWorkloadLeaf(AppInstance $appInstance, Route $route, Node $router): void
    {
        $address = is_string($appInstance->node->lan_ip) && $appInstance->node->lan_ip !== ''
            ? $appInstance->node->lan_ip
            : $appInstance->node->wireguard_ip;

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
