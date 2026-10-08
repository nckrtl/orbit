<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Routes\RouteRemovalNode;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Route;

final class FakeRouteRemovalProjector implements RouteRemovalProjector
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<int> */
    public array $routeIds = [];

    /** @var array<string, int> */
    public array $failures = [];

    /** @var list<RouteRemovalNode> */
    public array $nodes = [];

    /**
     * The Nodes each Node-side step was told to skip.
     *
     * @var array<string, list<int>>
     */
    public array $skipped = [];

    /**
     * The stored Route state each step sees.
     *
     * @var list<array{status: RouteStatus, sites_published: bool, targets: int}>
     */
    public array $storedStates = [];

    public function nodes(Route $route): array
    {
        return $this->nodes;
    }

    public function cleanupDns(Route $route): void
    {
        $this->event($route, 'dns');
    }

    public function cleanupCertificates(Route $route, array $skippedNodeIds = []): void
    {
        $this->skipped['certificates'] = $skippedNodeIds;
        $this->event($route, 'certificates');
    }

    public function cleanupCaddy(Route $route, array $skippedNodeIds = []): void
    {
        $this->skipped['caddy'] = $skippedNodeIds;
        $this->event($route, 'caddy');
    }

    public function cleanupFirewall(Route $route, array $skippedNodeIds = []): void
    {
        $this->skipped['firewall'] = $skippedNodeIds;
        $this->event($route, 'firewall');
    }

    public function cleanupPhp(Route $route, array $skippedNodeIds = []): void
    {
        $this->skipped['php'] = $skippedNodeIds;
        $this->event($route, 'php');
    }

    private function event(Route $route, string $event): void
    {
        $this->events[] = $event;
        $this->routeIds[] = $route->id;
        $stored = Route::query()->withCount('targets')->findOrFail($route->id);
        $this->storedStates[] = [
            'status' => $stored->status,
            'sites_published' => $stored->sites_published,
            'targets' => (int) $stored->getAttribute('targets_count'),
        ];

        if (($this->failures[$event] ?? 0) < 1) {
            return;
        }

        $this->failures[$event]--;

        throw new ResourceOperationException(
            errorCode: "route.test_{$event}",
            message: "Injected {$event} failure.",
        );
    }
}
