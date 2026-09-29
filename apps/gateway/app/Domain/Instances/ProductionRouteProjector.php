<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;
use App\Models\Route;

interface ProductionRouteProjector
{
    public function prepareRuntime(Instance $instance, Route $route): void;

    public function prepareCertificate(Instance $instance, Route $route): void;

    public function prepareFirewall(Instance $instance): void;

    public function publish(Instance $instance, Route $route): void;
}
