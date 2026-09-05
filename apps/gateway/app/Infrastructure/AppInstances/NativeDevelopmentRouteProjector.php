<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\DevelopmentRouteProjector;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;

final readonly class NativeDevelopmentRouteProjector implements DevelopmentRouteProjector
{
    public function __construct(
        private RemoteAppDevPhpFpmManager $php,
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevCaddyManager $caddy,
        private DnsmasqPrivateDnsManager $dns,
        private AppDevSshExecutor $ssh,
    ) {}

    public function converge(AppInstance $appInstance, Route $route): void
    {
        $appInstance->loadMissing('node');
        $route->loadMissing('cluster.routerAssignment.node');

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

        if ($router instanceof Node) {
            $this->certificates->convergeRouteRouter($route, $router);
            $this->convergeLanFirewall($appInstance, $route, $router);
            $this->verifyWorkloadLeaf($appInstance, $route, $router);
            $this->caddy->convergeRoute($router, $route);
        }

        // DNS is deliberately last. A failed earlier projection is never reachable by name.
        $this->dns->convergeRoute($route);
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
                    $route->hostname,
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
