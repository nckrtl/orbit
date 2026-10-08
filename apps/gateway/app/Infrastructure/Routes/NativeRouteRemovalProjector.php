<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Domain\AppDev\AppDevPhpFpmManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Routes\RouteRemovalProjector;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppDev\RemoteAppDevRouteFirewallManager;
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

    /**
     * Stored state renders no pool for a Route without published sites, so a convergence that
     * skipped another site's pool for a missing directory has still withdrawn this Route's pools.
     * Doctor reports the skipped pool; it must not keep this removal open.
     */
    public function cleanupPhp(Route $route): void
    {
        foreach ($this->developmentTargetNodes($route) as $node) {
            try {
                $this->php()->converge($node);
            } catch (RuntimeConvergenceException $exception) {
                if ($exception->errorCode !== RemoteAppDevPhpFpmManager::PoolDirectoryMissing) {
                    throw $exception;
                }
            }
        }
    }

    private function php(): AppDevPhpFpmManager
    {
        return $this->php ?? app(AppDevPhpFpmManager::class);
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

    /** @return Collection<int, Instance> */
    private function targetInstances(Route $route): Collection
    {
        return $route->targets
            ->map(static fn (RouteTarget $target): Instance => $target->instance)
            ->values();
    }
}
