<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterReplacementProjector;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Shared\LifecycleStatus;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;
use Closure;

final class FakeClusterRouterReplacementProjector implements ClusterRouterReplacementProjector
{
    /** @var list<string> */
    public array $events = [];

    /** @var array<string, int> */
    public array $failures = [];

    public int $applicationHttpStatus = 200;

    public ?Closure $onPrepare = null;

    /** Receives each event name before the event runs. */
    public ?Closure $observe = null;

    /** @var list<array{step: string, route_id: int, router_id: int, domain: string, workload_id: ?int}> */
    public array $placements = [];

    /**
     * The Router Nodes that stored state renders the Cluster's Router sites on, as each Router
     * build, restore, or cleanup starts.
     *
     * @var list<array{step: string, routers: list<int>}>
     */
    public array $serving = [];

    public function prepareRouterCertificate(Route $route, Node $router, AppInstance $workload): void
    {
        $this->event('router-certificate', $route, $router, $workload);
    }

    public function prepareFirewallPolicy(Route $route, Node $router, AppInstance $workload): void
    {
        $this->event('firewall-policy', $route, $router, $workload);
    }

    public function verifyWorkload(Route $route, Node $router, AppInstance $workload): void
    {
        $this->event('workload-verify', $route, $router, $workload);
    }

    public function prepareRouterCaddy(Route $route, Node $router): void
    {
        $route->loadMissing('targets.appInstance.node');
        $colocated = $route->targets->contains(
            static fn ($target): bool => $target->appInstance instanceof AppInstance
                && $router->is($target->appInstance->node),
        );
        $this->event($colocated ? 'router-caddy:local-next-hop' : 'router-caddy', $route, $router);
    }

    public function publishDns(Route $route, Node $router): void
    {
        $this->event('dns-publication', $route, $router);
    }

    public function cleanupOldRouter(Route $route, Node $oldRouter): void
    {
        $this->event('cleanup', $route, $oldRouter);
    }

    public function restore(Route $route, Node $newRouter, ?Node $oldRouter): void
    {
        $this->event('restore', $route, $newRouter);
    }

    private function recordServing(string $step, Route $route): void
    {
        $clusterId = (int) $route->cluster_id;
        $active = NodeRole::query()
            ->where('cluster_id', $clusterId)
            ->where('role', RoleName::Router)
            ->where('status', LifecycleStatus::Active)
            ->value('node_id');
        $second = array_map(
            static fn (Node $node): int => $node->id,
            new ClusterRouterTransition()->secondRouters()[$clusterId] ?? [],
        );
        $routers = array_values(array_unique(array_filter([$active, ...$second], is_int(...))));
        sort($routers);
        $this->serving[] = ['step' => $step, 'routers' => $routers];
    }

    private function event(string $name, Route $route, Node $router, ?AppInstance $workload = null): void
    {
        if ($this->observe instanceof Closure) {
            ($this->observe)($name);
        }

        if (in_array($name, ['router-caddy', 'router-caddy:local-next-hop', 'restore', 'cleanup'], true)) {
            $this->recordServing($name, $route);
        }

        if ($this->onPrepare instanceof Closure && ! in_array($name, ['dns-publication', 'cleanup', 'restore'], true)) {
            ($this->onPrepare)();
        }

        $this->events[] = $name;
        $this->placements[] = [
            'step' => $name,
            'route_id' => $route->id,
            'router_id' => $router->id,
            'domain' => $route->domain,
            'workload_id' => $workload?->id,
        ];

        if (($this->failures[$name] ?? 0) > 0) {
            $this->failures[$name]--;

            throw new RuntimeConvergenceException(
                step: $name === 'restore'
                    ? 'rollback:router-caddy'
                    : (str_starts_with($name, 'router-caddy') ? 'router-caddy' : $name),
                errorCode: "route.test_{$name}",
                message: "Injected {$name} failure.",
            );
        }
    }
}
