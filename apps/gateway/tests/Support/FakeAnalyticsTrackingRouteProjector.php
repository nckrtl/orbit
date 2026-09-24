<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Analytics\AnalyticsTrackingRouteProjector;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Models\Route;

final class FakeAnalyticsTrackingRouteProjector implements AnalyticsTrackingRouteProjector
{
    /** @var list<int> */
    public array $routeIds = [];

    public int $failures = 0;

    /** @var list<string> */
    public array $events = [];

    public ?string $failAt = null;

    public function converge(Route $route): void
    {
        $this->routeIds[] = $route->id;

        if ($this->failures < 1) {
            return;
        }

        $this->failures--;

        throw new RuntimeConvergenceException(
            step: 'projection',
            errorCode: 'route.test_projection',
            message: 'Injected projection failure.',
        );
    }

    public function prepareHost(Route $candidate): void
    {
        $this->event('prepare', $candidate);
    }

    public function buildHost(Route $placement): void
    {
        $this->event('build', $placement);
    }

    public function publishDns(): void
    {
        $this->event('dns');
    }

    public function withdrawHost(Route $retired, Route $current): void
    {
        $this->event('withdraw', $retired);
    }

    private function event(string $name, ?Route $placement = null): void
    {
        $this->events[] = $placement instanceof Route
            ? "{$name}:".($placement->node_id === null ? "cluster-{$placement->cluster_id}" : "node-{$placement->node_id}")
            : $name;

        if ($this->failAt === $name) {
            $this->failAt = null;

            throw new RuntimeConvergenceException(
                step: "tracking-{$name}",
                errorCode: "route.test_tracking_{$name}",
                message: "Injected tracking {$name} failure.",
            );
        }
    }
}
