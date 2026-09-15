<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Routes\ClusterRouterReplacementProjector;
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

    public function prepareRouterCertificate(Route $route, Node $router, AppInstance $workload): void
    {
        if ($this->colocated($router, $workload)) {
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
        if ($this->colocated($router, $workload)) {
            return;
        }

        $this->verifyLeaf($workload, $route, $router);
    }

    public function prepareRouterCaddy(Route $route, Node $router): void
    {
        $clusterId = $route->cluster_id;

        if (! is_int($clusterId)) {
            return;
        }

        $this->caddy->convergeRoute($router, $route, [$clusterId => $router->id]);
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

    public function cleanupOldRouter(Route $route, Node $oldRouter): void
    {
        foreach ($this->workloads($route) as $workload) {
            if ($this->colocated($oldRouter, $workload)) {
                continue;
            }

            $this->certificates->removeRouteRouter($route, $oldRouter);
            $this->firewall->remove($workload->node, $route->id);
        }

        $this->caddy->converge($oldRouter);
    }

    public function restore(Route $route, Node $newRouter, ?Node $oldRouter): void
    {
        $clusterId = $route->cluster_id;

        foreach ($this->workloads($route) as $workload) {
            if ($this->colocated($newRouter, $workload)) {
                continue;
            }

            $this->certificates->removeRouteRouter($route, $newRouter);
            $this->firewall->remove($workload->node, $route->id);
        }

        $this->caddy->converge($newRouter);

        if (is_int($clusterId) && $oldRouter instanceof Node) {
            $this->dns->convergeSelection(
                clusterOverrides: [$clusterId => ['router_node_id' => $oldRouter->id]],
            );
        }
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
