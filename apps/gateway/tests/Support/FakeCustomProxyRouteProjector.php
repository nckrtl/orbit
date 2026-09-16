<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Routes\CustomProxyRouteProjector;
use App\Models\Route;

final class FakeCustomProxyRouteProjector implements CustomProxyRouteProjector
{
    /** @var list<int> */
    public array $routeIds = [];

    public function converge(Route $route): void
    {
        $this->routeIds[] = $route->id;
    }
}
