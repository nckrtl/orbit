<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Routes\IngressSite;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\PublicRoutePrivateOverride;
use App\Models\Route;

final class FakePublicRouteEdgeProjector implements PublicRouteEdgeProjector
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, int> */
    public array $failures = [];

    public function artifact(Route $route): IngressSite
    {
        $this->calls[] = 'artifact';

        return new IngressSite(
            domain: $route->domain,
            routerUpstream: '10.10.0.20',
            ingressNodeId: 1,
            certificateScope: "route-{$route->id}-ingress",
            activated: false,
        );
    }

    public function privateOverride(Route $route): PublicRoutePrivateOverride
    {
        $this->calls[] = 'private-override';

        return new PublicRoutePrivateOverride($route->domain, '10.10.0.20', '10.10.0.10');
    }

    public function prepareIngressCertificate(Route $route): void
    {
        $this->event('ingress-certificate');
    }

    public function stageIngressCaddy(Route $route): void
    {
        $this->event('ingress-caddy');
    }

    public function verifyPublicEdge(Route $route): void
    {
        $this->event('public-edge-verified');
    }

    public function activatePublicHandler(Route $route): void
    {
        $this->event('public-activated');
    }

    public function prepareIngressFirewall(Route $route): void
    {
        $this->event('ingress-firewall');
    }

    public function rollbackPublicEdge(Route $route): void
    {
        $this->event('rollback-public-edge');
    }

    public function removePublicEdge(Route $route): void
    {
        $this->event('remove-public-edge');
    }

    private function event(string $event): void
    {
        $this->calls[] = $event;

        if (($this->failures[$event] ?? 0) < 1) {
            return;
        }

        $this->failures[$event]--;

        throw new RuntimeConvergenceException(
            step: $event,
            errorCode: "route.test_{$event}",
            message: "Injected {$event} failure.",
        );
    }
}
