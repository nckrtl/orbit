<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Domain\AppDev\AppDevPhpFpmManager;
use App\Domain\Routes\RouteRemovalNode;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteRemovalStep;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevRouteFirewallManager;
use App\Infrastructure\AppDev\WithdrawnSitePhpConvergence;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteTarget;
use Illuminate\Support\Collection;

final readonly class NativeRouteRemovalProjector implements RouteRemovalProjector
{
    public function __construct(
        private DnsmasqPrivateDnsManager $dns,
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevCaddyManager $caddy,
        private RemoteAppDevRouteFirewallManager $firewall,
        private ?AppDevPhpFpmManager $php = null,
    ) {}

    public function nodes(Route $route): array
    {
        $nodes = [];
        $steps = [];
        $stepNodes = [
            [RouteRemovalStep::Caddy, $this->projectionNodes($route)],
            [RouteRemovalStep::Php, $this->developmentTargetNodes($route)],
            [RouteRemovalStep::Certificates, $this->certificateNodes($route)],
            [RouteRemovalStep::Firewall, $this->workloadNodes($route)],
        ];

        foreach ($stepNodes as [$step, $acting]) {
            foreach ($acting as $node) {
                $nodes[$node->id] = $node;
                $steps[$node->id][] = $step;
            }
        }

        return array_values(array_map(
            static fn (Node $node): RouteRemovalNode => new RouteRemovalNode($node, $steps[$node->id]),
            $nodes,
        ));
    }

    public function cleanupDns(Route $route): void
    {
        $this->dns->converge();
    }

    public function cleanupCertificates(Route $route, array $skippedNodeIds = []): void
    {
        $route->loadMissing('node');

        if ($route->isCustomProxy() && $route->node instanceof Node && ! in_array($route->node->id, $skippedNodeIds, true)) {
            $this->certificates->removeCustomProxy($route, $route->node);
        }

        if ($route->hasWebRoot()) {
            foreach ($this->without($this->targetNodes($route), $skippedNodeIds) as $node) {
                $this->certificates->removeRouteLeaf($route, $node);
            }
        }

        $router = $this->router($route);

        if (! $router instanceof Node || in_array($router->id, $skippedNodeIds, true)) {
            return;
        }

        $this->certificates->removeRouteRouter($route, $router);
        $this->certificates->removeRouteRouterHostnameChange($route, $router);
    }

    public function cleanupCaddy(Route $route, array $skippedNodeIds = []): void
    {
        foreach ($this->without($this->projectionNodes($route), $skippedNodeIds) as $node) {
            $this->caddy->build($node);
        }
    }

    public function cleanupFirewall(Route $route, array $skippedNodeIds = []): void
    {
        foreach ($this->without($this->workloadNodes($route), $skippedNodeIds) as $node) {
            $this->firewall->remove($node, $route->id);
        }
    }

    public function cleanupPhp(Route $route, array $skippedNodeIds = []): void
    {
        $php = new WithdrawnSitePhpConvergence($this->php ?? app(AppDevPhpFpmManager::class));

        foreach ($this->without($this->developmentTargetNodes($route), $skippedNodeIds) as $node) {
            $php->converge($node);
        }
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @param  list<int>  $skippedNodeIds
     * @return Collection<int, Node>
     */
    private function without(Collection $nodes, array $skippedNodeIds): Collection
    {
        return $nodes
            ->reject(static fn (Node $node): bool => in_array($node->id, $skippedNodeIds, true))
            ->values();
    }

    private function router(Route $route): ?Node
    {
        $route->loadMissing('cluster.routerAssignment.node');
        $router = $route->cluster?->routerAssignment?->node;

        return $router instanceof Node ? $router : null;
    }

    /** @return Collection<int, Node> */
    private function certificateNodes(Route $route): Collection
    {
        $route->loadMissing('node');

        return collect([$route->isCustomProxy() ? $route->node : null, $this->router($route)])
            ->merge($route->hasWebRoot() ? $this->targetNodes($route) : [])
            ->filter(static fn (mixed $node): bool => $node instanceof Node)
            ->unique(static fn (Node $node): int => $node->id)
            ->values();
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
        $route->loadMissing(['node', 'generationBasisNode', 'targets.instance.node']);

        return collect([$route->node, $route->generationBasisNode])
            ->merge($this->targetInstances($route)->map(static fn (Instance $instance): Node => $instance->node))
            ->filter(static fn (mixed $node): bool => $node instanceof Node)
            ->unique(static fn (Node $node): int => $node->id)
            ->values();
    }

    /**
     * A production target keeps its dedicated PHP-FPM service until Instance removal, so only
     * development targets share the Node pools that removal withdraws.
     *
     * @return Collection<int, Node>
     */
    private function developmentTargetNodes(Route $route): Collection
    {
        $route->loadMissing('targets.instance.node');

        return $this->targetInstances($route)
            ->reject(static fn (Instance $instance): bool => $instance->placedOnAppProd())
            ->map(static fn (Instance $instance): Node => $instance->node)
            ->unique(static fn (Node $node): int => $node->id)
            ->values();
    }

    /** @return Collection<int, Node> */
    private function targetNodes(Route $route): Collection
    {
        $route->loadMissing('targets.instance.node');

        return $this->targetInstances($route)
            ->map(static fn (Instance $instance): Node => $instance->node)
            ->unique(static fn (Node $node): int => $node->id)
            ->values();
    }

    /** @return Collection<int, Instance> */
    private function targetInstances(Route $route): Collection
    {
        return $route->targets
            ->map(static fn (RouteTarget $target): Instance => $target->instance)
            ->values();
    }
}
