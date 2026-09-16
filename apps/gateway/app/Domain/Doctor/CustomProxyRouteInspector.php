<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\RouteCustomProxy;

interface CustomProxyRouteInspector
{
    public function inspect(RouteCustomProxy $proxy): CustomProxyRouteObservation;
}
