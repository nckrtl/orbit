<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\IngressSite;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\PublicRoutePrivateOverride;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\Route;

final readonly class NativePublicRouteEdgeProjector implements PublicRouteEdgeProjector
{
    public function __construct(
        private IngressSiteRepository $sites,
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevCaddyManager $caddy,
        private NodeRoleFirewallManager $firewall,
        private AppDevSshExecutor $ssh,
    ) {}

    public function artifact(Route $route): IngressSite
    {
        return $this->sites->forRoute($route);
    }

    public function privateOverride(Route $route): PublicRoutePrivateOverride
    {
        return $this->sites->privateOverride($route);
    }

    public function prepareIngressCertificate(Route $route): void
    {
        // Public Ingress uses Caddy automatic HTTPS (Let's Encrypt). Confirm Ingress exists
        // and do not pin an Orbit CA leaf that would replace a working public certificate.
        $this->sites->ingressNode($route);
    }

    public function verifyPublicEdge(Route $route): void
    {
        $ingress = $this->sites->ingressNode($route);
        $router = $this->sites->routerNode($route);
        $override = $this->sites->privateOverride($route);

        if (is_string($router->lan_ip) && $router->lan_ip !== '' && $override->routerAddress !== $router->lan_ip) {
            throw new RuntimeConvergenceException(
                step: 'route-address',
                errorCode: 'route.lan_unreachable',
                message: 'The configured Ingress LAN path does not match the Router LAN address.',
            );
        }

        $this->verifyHop($ingress, $override->routerAddress, $route->domain, 'ingress-forwarding');

        if ($override->workloadAddress !== $override->routerAddress) {
            $this->verifyHop($router, $override->workloadAddress, $route->domain, 'router-forwarding');
        }
    }

    public function activatePublicHandler(Route $route): void
    {
        $this->caddy->build($this->sites->ingressNode($route));
    }

    public function prepareIngressFirewall(Route $route): void
    {
        $ingress = $this->sites->ingressNode($route);
        $this->firewall->converge($ingress, RoleName::Ingress, $ingress->user);
    }

    public function rollbackPublicEdge(Route $route): void
    {
        $ingress = $this->sites->ingressNode($route);
        $this->caddy->build($ingress);
        $this->firewall->converge($ingress, RoleName::Ingress, $ingress->user);
        $this->certificates->removeRouteIngress($route, $ingress);
    }

    public function removePublicEdge(Route $route): void
    {
        $this->rollbackPublicEdge($route);
    }

    private function verifyHop(Node $from, string $address, string $domain, string $step): void
    {
        $this->ssh->execute(
            $from,
            new RemoteCommand(
                [
                    'timeout',
                    '10',
                    'openssl',
                    's_client',
                    '-connect',
                    "{$address}:443",
                    '-servername',
                    $domain,
                    '-verify_return_error',
                ],
                input: '',
            ),
            step: $step,
            errorCode: 'route.public_edge_unverified',
            commandTimeout: 15,
        );
    }
}
