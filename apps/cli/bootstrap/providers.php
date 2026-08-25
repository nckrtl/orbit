<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\LaravelBoostCompatibilityServiceProvider;
use Illuminate\Routing\RoutingServiceProvider;
use Laravel\Boost\BoostServiceProvider;
use Spatie\Guidelines\GuidelinesServiceProvider;

$providers = [
    AppServiceProvider::class,
];

if (class_exists(BoostServiceProvider::class)) {
    $providers[] = RoutingServiceProvider::class;
    $providers[] = BoostServiceProvider::class;
    $providers[] = LaravelBoostCompatibilityServiceProvider::class;
}

if (class_exists(GuidelinesServiceProvider::class)) {
    $providers[] = GuidelinesServiceProvider::class;
}

return $providers;
