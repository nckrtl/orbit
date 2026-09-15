<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Routes\RouteRemovalProjector;
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

    public function cleanupDns(Route $route): void
    {
        $this->event($route, 'dns');
    }

    public function cleanupCertificates(Route $route): void
    {
        $this->event($route, 'certificates');
    }

    public function cleanupCaddy(Route $route): void
    {
        $this->event($route, 'caddy');
    }

    public function cleanupFirewall(Route $route): void
    {
        $this->event($route, 'firewall');
    }

    private function event(Route $route, string $event): void
    {
        $this->events[] = $event;
        $this->routeIds[] = $route->id;

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
