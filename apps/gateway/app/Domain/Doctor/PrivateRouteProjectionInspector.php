<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Instance;
use App\Models\Route;

interface PrivateRouteProjectionInspector
{
    public function inspect(Instance $instance, Route $route): PrivateRouteProjectionObservation;
}
