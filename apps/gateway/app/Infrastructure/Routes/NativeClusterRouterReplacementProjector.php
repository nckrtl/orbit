<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Routes\ClusterRouterReplacementProjector;
use App\Domain\Routes\RouteCertificateStaging;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevRouteFirewallManager;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;

final readonly class NativeClusterRouterReplacementProjector implements ClusterRouterReplacementProjector
{
    public function __construct(
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevCaddyManager $caddy,
        private RemoteAppDevRouteFirewallManager $firewall,
        private DnsmasqPrivateDnsManager $dns,
        private AppDevSshExecutor $ssh,
    ) {}

    /**
     * The candidate serves each Router site with the certificate scope its stored state names. A
     * domain change replacement answers from its staging Router scope until its cleanup, also
     * when a crash left it `pending`, and only once its own change has issued that scope.
     */
    public function prepareRouterCertificate(Route $route, Node $router, AppInstance $workload): void
    {
        if ($this->colocated($router, $workload) || ! $this->rendersRouterSites($route)) {
            return;
        }

        if (RouteCertificateStaging::router($route)) {
            $this->certificates->convergeRouteRouterHostnameChange($route, $router);

            return;
        }

        $this->certificates->convergeRouteRouter($route, $router);
    }

    public function prepareFirewallPolicy(Route $route, Node $router, AppInstance $workload): void
    {
        if ($this->colocated($router, $workload)) {
            return;
        }

        $this->allowLan($workload, $route, $router);
    }

    public function verifyWorkload(Route $route, Node $router, AppInstance $workload): void
    {
        if ($this->colocated($router, $workload) || ! $this->rendersRouterSites($route)) {
            return;
        }

        $this->verifyLeaf($workload, $route, $router);
    }

    /** The candidate `router` row's stored step makes it serve the Cluster's Router sites. */
    public function prepareRouterCaddy(Route $route, Node $router): void
    {
        if (! is_int($route->cluster_id)) {
            return;
        }

        $this->caddy->converge($router);
    }

    public function publishDns(Route $route, Node $router): void
    {
        $clusterId = $route->cluster_id;

        if (! is_int($clusterId)) {
            return;
        }

        $this->dns->convergeSelection(
            clusterOverrides: [$clusterId => ['router_node_id' => $router->id]],
        );
    }

    /**
     * The old Router row is marked first, so this build drops the Router sites before their
     * certificates and firewall rules are removed.
     */
    public function cleanupOldRouter(Route $route, Node $oldRouter): void
    {
        $this->caddy->converge($oldRouter);

        foreach ($this->workloads($route) as $workload) {
            if ($this->colocated($oldRouter, $workload)) {
                continue;
            }

            $this->certificates->removeRouteRouter($route, $oldRouter);
            $this->certificates->removeRouteRouterHostnameChange($route, $oldRouter);
            $this->firewall->remove($workload->node, $route->id);
        }
    }

    /**
     * The candidate row is marked first, so this build drops the Router sites from the candidate
     * before its certificates and firewall rules are removed. The old Router keeps serving.
     */
    public function restore(Route $route, Node $newRouter, ?Node $oldRouter): void
    {
        $clusterId = $route->cluster_id;
        $this->caddy->converge($newRouter);

        foreach ($this->workloads($route) as $workload) {
            if ($this->colocated($newRouter, $workload)) {
                continue;
            }

            $this->certificates->removeRouteRouter($route, $newRouter);
            $this->certificates->removeRouteRouterHostnameChange($route, $newRouter);
            $this->firewall->remove($workload->node, $route->id);
        }

        if (is_int($clusterId) && $oldRouter instanceof Node) {
            $this->dns->convergeSelection(
                clusterOverrides: [$clusterId => ['router_node_id' => $oldRouter->id]],
            );
        }
    }

    /**
     * Whether a build renders the Route's Router sites: a published Route, or a pending domain change
     * replacement once its own change has issued the staging Router certificate. A failed
     * replacement and an earlier pending one render nothing.
     */
    private function rendersRouterSites(Route $route): bool
    {
        if ($route->sites_published) {
            return true;
        }

        return $route->status === RouteStatus::Pending
            && $route->replaces_route_id !== null
            && RouteCertificateStaging::reached($route, RouteReplacementStep::RouterCertificate);
    }

    private function colocated(Node $router, AppInstance $workload): bool
    {
        $workload->loadMissing('node');

        return $router->is($workload->node);
    }

    /** @return list<AppInstance> */
    private function workloads(Route $route): array
    {
        $route->loadMissing('targets.appInstance.node');

        return $route
            ->targets
            ->map(static fn ($target) => $target->appInstance)
            ->filter(static fn ($target): bool => $target instanceof AppInstance)
            ->values()
            ->all();
    }

    private function allowLan(AppInstance $appInstance, Route $route, Node $router): void
    {
        $appInstance->loadMissing('node');
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

    private function verifyLeaf(AppInstance $appInstance, Route $route, Node $router): void
    {
        $appInstance->loadMissing('node');
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
