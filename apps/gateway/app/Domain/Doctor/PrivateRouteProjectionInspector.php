<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\AppInstance;
use App\Models\Route;

interface PrivateRouteProjectionInspector
{
    public function inspect(AppInstance $instance, Route $route): PrivateRouteProjectionObservation;
}
