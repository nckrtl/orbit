<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\Instance;
use App\Models\Route;

interface DevelopmentRouteProjector
{
    public function converge(Instance $appInstance, Route $route): void;
}
