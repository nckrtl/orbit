<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;
use App\Models\Route;

interface ProductionRouteProjector
{
    public function prepareRuntime(AppInstance $appInstance, Route $route): void;

    public function prepareCertificate(AppInstance $appInstance, Route $route): void;

    public function prepareFirewall(AppInstance $appInstance): void;

    public function publish(AppInstance $appInstance, Route $route): void;
}
