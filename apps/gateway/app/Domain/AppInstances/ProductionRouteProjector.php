<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\Instance;
use App\Models\Route;

interface ProductionRouteProjector
{
    public function prepareRuntime(Instance $appInstance, Route $route): void;

    public function prepareCertificate(Instance $appInstance, Route $route): void;

    public function prepareFirewall(Instance $appInstance): void;

    public function publish(Instance $appInstance, Route $route): void;
}
