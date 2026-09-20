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
}
