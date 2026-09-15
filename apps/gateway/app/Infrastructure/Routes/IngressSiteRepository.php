<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Routes\IngressSite;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\PublicRoutePrivateOverride;
use App\Domain\Routes\RoutePublicPublication;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;

final readonly class IngressSiteRepository
{
    public function __construct(
        private PublicRouteEligibility $eligibility = new PublicRouteEligibility,
    ) {}

    public function forRoute(Route $route): IngressSite
    {
        $route->loadMissing(['cluster.routerAssignment.node', 'targets.appInstance.node']);
        $cluster = $route->cluster;
        $ingress = $cluster !== null ? $this->eligibility->activeIngress($cluster) : null;
        $router = $cluster !== null ? $this->eligibility->activeRouter($cluster) : null;

        if ($ingress === null || $router === null) {
            throw new RuntimeConvergenceException(
                step: 'ingress',
                errorCode: 'route.ingress_required',
                message: 'A public Route requires one active Ingress and one active Router.',
            );
        }

        return new IngressSite(
            domain: $route->domain,
            routerUpstream: $this->routerUpstream($router, $ingress),
            ingressNodeId: $ingress->id,
            certificateScope: "route-{$route->id}-ingress",
            activated: $route->public_publication === RoutePublicPublication::Active,
        );
    }

    public function privateOverride(Route $route): PublicRoutePrivateOverride
    {
        $route->loadMissing(['cluster.routerAssignment.node', 'targets.appInstance.node']);
        $cluster = $route->cluster;
        $router = $cluster !== null ? $this->eligibility->activeRouter($cluster) : null;
        $target = $route->targets->first()?->appInstance;

        if (! $router instanceof Node || ! $target instanceof AppInstance) {
            throw new RuntimeConvergenceException(
                step: 'route-address',
                errorCode: 'route.private_override_unavailable',
                message: 'A private domain override requires a Router and a workload target.',
            );
        }

        return new PublicRoutePrivateOverride(
            domain: $route->domain,
            routerAddress: $this->privateAddress($router),
            workloadAddress: $this->privateAddress($target->node),
        );
    }

    public function ingressNode(Route $route): Node
    {
        $route->loadMissing('cluster');
        $cluster = $route->cluster;
        $ingress = $cluster !== null ? $this->eligibility->activeIngress($cluster) : null;

        if (! $ingress instanceof Node) {
            throw new RuntimeConvergenceException(
                step: 'ingress',
                errorCode: 'route.ingress_required',
                message: 'A public Route requires one active Ingress.',
            );
        }

        return $ingress;
    }

    public function routerNode(Route $route): Node
    {
        $route->loadMissing('cluster.routerAssignment.node');
        $cluster = $route->cluster;
        $router = $cluster !== null ? $this->eligibility->activeRouter($cluster) : null;

        if (! $router instanceof Node) {
            throw new RuntimeConvergenceException(
                step: 'router',
                errorCode: 'cluster.router_required',
                message: 'A public Route requires one active Router.',
            );
        }

        return $router;
    }

    private function routerUpstream(Node $router, Node $ingress): string
    {
        if ($router->is($ingress)) {
            return '127.0.0.1';
        }

        return $this->privateAddress($router);
    }

    private function privateAddress(Node $node): string
    {
        if (is_string($node->lan_ip) && $node->lan_ip !== '') {
            return $node->lan_ip;
        }

        if (is_string($node->wireguard_ip) && $node->wireguard_ip !== '') {
            return $node->wireguard_ip;
        }

        throw new RuntimeConvergenceException(
            step: 'route-address',
            errorCode: 'route.workload_address_missing',
            message: "Node [{$node->name}] has no private address for Route forwarding.",
        );
    }
}
