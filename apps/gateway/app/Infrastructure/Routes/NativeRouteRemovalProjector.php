<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Domain\Routes\RouteRemovalProjector;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevRouteFirewallManager;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Collection;

final readonly class NativeRouteRemovalProjector implements RouteRemovalProjector
{
    public function __construct(
        private DnsmasqPrivateDnsManager $dns,
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevCaddyManager $caddy,
        private RemoteAppDevRouteFirewallManager $firewall,
    ) {}

    public function cleanupDns(Route $route): void
    {
        $this->dns->converge();
    }

    public function cleanupCertificates(Route $route): void
    {
        $route->loadMissing('node');

        if ($route->isCustomProxy() && $route->node instanceof Node) {
            $this->certificates->removeCustomProxy($route, $route->node);
        }

        $router = $this->router($route);

        if (! $router instanceof Node) {
            return;
        }

        $this->certificates->removeRouteRouter($route, $router);
        $this->certificates->removeRouteRouterHostnameChange($route, $router);
    }

    public function cleanupCaddy(Route $route): void
    {
        foreach ($this->projectionNodes($route) as $node) {
            $this->caddy->build($node);
        }
    }

    public function cleanupFirewall(Route $route): void
    {
        foreach ($this->workloadNodes($route) as $node) {
            $this->firewall->remove($node, $route->id);
        }
    }

    private function router(Route $route): ?Node
    {
        $route->loadMissing('cluster.routerAssignment.node');
        $router = $route->cluster?->routerAssignment?->node;

        return $router instanceof Node ? $router : null;
    }

    /** @return Collection<int, Node> */
    private function projectionNodes(Route $route): Collection
    {
        return $this->workloadNodes($route)
            ->merge($this->router($route) instanceof Node ? [$this->router($route)] : [])
            ->unique(static fn (Node $node): int => $node->id)
            ->values();
    }

    /** @return Collection<int, Node> */
    private function workloadNodes(Route $route): Collection
    {
        $route->loadMissing(['node', 'generationBasisNode']);

        return collect([$route->node, $route->generationBasisNode])
            ->filter(static fn (mixed $node): bool => $node instanceof Node)
            ->unique(static fn (Node $node): int => $node->id)
            ->values();
    }
}
