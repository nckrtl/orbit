<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Models\Route;

final readonly class ShowRouteAction
{
    public function handle(Route $route): Route
    {
        return $route->load('targets');
    }
}
