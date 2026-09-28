<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\Instance;
use App\Models\Route;

interface ProductionCloneRouteProjector
{
    public function prepareWorkloadCaddy(Instance $appInstance, Route $route): void;

    public function prepareRouterCertificate(Instance $appInstance, Route $route): void;

    public function prepareRouteFirewall(Instance $appInstance, Route $route): void;

    public function verifyWorkload(Instance $appInstance, Route $route): void;

    public function prepareRouterCaddy(Instance $appInstance, Route $route): void;

    public function prepareDns(Route $route): void;
}
