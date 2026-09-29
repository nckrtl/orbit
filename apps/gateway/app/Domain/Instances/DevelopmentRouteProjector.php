<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;
use App\Models\Route;

interface DevelopmentRouteProjector
{
    public function converge(Instance $instance, Route $route): void;
}
