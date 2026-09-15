<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\IngressSite;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\PublicRoutePrivateOverride;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
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
        private AppDevCaddyConfigRenderer $renderer = new AppDevCaddyConfigRenderer,
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
        $this->certificates->convergeRouteIngress($route, $this->sites->ingressNode($route));
    }

    public function stageIngressCaddy(Route $route): void
    {
        $ingress = $this->sites->ingressNode($route);
        $artifact = $this->sites->forRoute($route);
        $configuration = $this->renderer->render(collect([
            new AppDevSite(
                nodeId: $ingress->id,
                nodeAddress: $ingress->wireguard_ip ?? '',
                scope: $artifact->certificateScope,
                checkoutPath: '',
                documentRoot: '',
                phpVersion: null,
                domain: $artifact->domain,
                upstreamAddresses: [$artifact->routerUpstream],
                certificateScope: $artifact->certificateScope,
                publicListener: true,
                preserveForwardedIdentity: true,
            ),
        ]));
        $encoded = base64_encode($configuration);
        $this->ssh->execute(
            $ingress,
            new RemoteCommand(
                arguments: [
                    'sudo',
                    'bash',
                    '-seu',
                    '--',
                    "route-{$route->id}-ingress",
                    $encoded,
                ],
                input: <<<'BASH'
                    scope=$1
                    encoded=$2
                    staged="/etc/caddy/orbit-versions/staged"
                    mkdir -p -- "$staged"
                    printf '%s' "$encoded" | base64 -d > "$staged/$scope.caddy"
                    BASH,
            ),
            step: 'ingress-caddy',
            errorCode: 'route.ingress_caddy_failed',
        );
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
        $this->caddy->converge($this->sites->ingressNode($route));
    }

    public function prepareIngressFirewall(Route $route): void
    {
        $ingress = $this->sites->ingressNode($route);
        $this->firewall->converge($ingress, RoleName::Ingress, $ingress->user);
    }

    public function rollbackPublicEdge(Route $route): void
    {
        $this->removeStaged($route);
        $ingress = $this->sites->ingressNode($route);
        $this->caddy->converge($ingress);
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

    private function removeStaged(Route $route): void
    {
        $ingress = $this->sites->ingressNode($route);
        $this->ssh->execute(
            $ingress,
            new RemoteCommand(
                arguments: [
                    'sudo',
                    'rm',
                    '-f',
                    '--',
                    "/etc/caddy/orbit-versions/staged/route-{$route->id}-ingress.caddy",
                ],
            ),
            step: 'ingress-caddy',
            errorCode: 'route.ingress_caddy_failed',
        );
    }
}
