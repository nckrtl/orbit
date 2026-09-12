<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;
use App\Models\Route;

interface ProductionCloneRouteProjector
{
    public function prepareCaddy(AppInstance $appInstance, Route $route): void;

    public function prepareDns(Route $route): void;
}
