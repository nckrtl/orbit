<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Route;

interface CustomProxyRouteProjector
{
    public function converge(Route $route): void;
}
