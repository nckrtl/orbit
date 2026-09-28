<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;
use App\Models\Route;

interface ProductionCloneRouteProjector
{
    public function prepareWorkloadCaddy(Instance $instance, Route $route): void;

    public function prepareRouterCertificate(Instance $instance, Route $route): void;

    public function prepareRouteFirewall(Instance $instance, Route $route): void;

    public function verifyWorkload(Instance $instance, Route $route): void;

    public function prepareRouterCaddy(Instance $instance, Route $route): void;

    public function prepareDns(Route $route): void;
}
