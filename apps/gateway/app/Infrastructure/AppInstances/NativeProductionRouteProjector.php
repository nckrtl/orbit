<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\ProductionCloneRouteProjector;
use App\Domain\AppInstances\ProductionPhpRuntimeManager;
use App\Domain\AppInstances\ProductionReleaseLayout;
use App\Domain\AppInstances\ProductionRouteProjector;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;

final readonly class NativeProductionRouteProjector implements ProductionCloneRouteProjector, ProductionRouteProjector
{
    public function __construct(
        private ProductionPhpRuntimeManager $productionPhp,
        private RemoteAppDevPhpFpmManager $sharedPhp,
        private RemoteAppDevCertificateManager $certificates,
        private NodeRoleFirewallManager $firewall,
        private RemoteAppDevCaddyManager $caddy,
        private DnsmasqPrivateDnsManager $dns,
        private ProductionReleaseLayout $releaseLayout,
        private AppDevSshExecutor $ssh,
    ) {}

    public function prepareRuntime(AppInstance $appInstance, Route $route): void
    {
        $appInstance->loadMissing('node');
        if ($appInstance->production_php_service !== null) {
            $this->productionPhp->converge($appInstance);

            return;
        }

        $this->sharedPhp->convergeRoute($appInstance->node, $route);
    }

    public function prepareCertificate(AppInstance $appInstance, Route $route): void
    {
        $this->certificates->convergeAppInstance($appInstance, $route);
    }

    public function prepareFirewall(AppInstance $appInstance): void
    {
        $appInstance->loadMissing('node');
        $this->firewall->converge($appInstance->node, RoleName::AppProd, $appInstance->node->user);
    }

    public function publish(AppInstance $appInstance, Route $route): void
    {
        $this->releaseLayout->validateCurrent($appInstance);

        $this->prepareWorkloadCaddy($appInstance, $route);
        $this->prepareRouterCaddy($appInstance, $route);
        $this->prepareDns($route);
    }

    public function prepareWorkloadCaddy(AppInstance $appInstance, Route $route): void
    {
        $appInstance->loadMissing('node');
        $this->caddy->convergeRoute($appInstance->node, $route);
    }

    public function prepareRouterCertificate(AppInstance $appInstance, Route $route): void
    {
        $router = $this->router($appInstance, $route);

        if ($router instanceof Node) {
            $this->certificates->convergeRouteRouter($route, $router);
        }
    }

    public function prepareRouteFirewall(AppInstance $appInstance, Route $route): void
    {
        $router = $this->router($appInstance, $route);

        if (! $router instanceof Node) {
            return;
        }

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

    public function verifyWorkload(AppInstance $appInstance, Route $route): void
    {
        $router = $this->router($appInstance, $route);

        if (! $router instanceof Node) {
            return;
        }

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

    public function prepareRouterCaddy(AppInstance $appInstance, Route $route): void
    {
        $router = $this->router($appInstance, $route);

        if ($router instanceof Node) {
            $this->caddy->convergeRoute($router, $route);
        }
    }

    public function prepareDns(Route $route): void
    {
        $this->dns->convergeRoute($route);
    }

    private function router(AppInstance $appInstance, Route $route): ?Node
    {
        $appInstance->loadMissing('node');
        $route->loadMissing('cluster.routerAssignment.node');
        $router = $route->cluster?->routerAssignment?->node;

        if ($route->cluster_id !== null && ! $router instanceof Node) {
            throw new RuntimeConvergenceException(
                step: 'router',
                errorCode: 'cluster.router_required',
                message: 'The Cluster Route requires an active Router.',
            );
        }

        return $router instanceof Node && ! $router->is($appInstance->node) ? $router : null;
    }
}
