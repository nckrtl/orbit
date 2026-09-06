<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;
use App\Models\Route;

interface DevelopmentRouteProjector
{
    public function converge(AppInstance $appInstance, Route $route): void;
}
