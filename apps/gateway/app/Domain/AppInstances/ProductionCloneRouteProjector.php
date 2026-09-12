<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;
use App\Models\Route;

interface ProductionCloneRouteProjector
{
    public function prepareWorkloadCaddy(AppInstance $appInstance, Route $route): void;

    public function prepareRouterCertificate(AppInstance $appInstance, Route $route): void;

    public function prepareRouteFirewall(AppInstance $appInstance, Route $route): void;

    public function verifyWorkload(AppInstance $appInstance, Route $route): void;

    public function prepareRouterCaddy(AppInstance $appInstance, Route $route): void;

    public function prepareDns(Route $route): void;
}
