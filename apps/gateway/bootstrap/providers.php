<?php

declare(strict_types=1);

use App\Providers\ApplicationServiceProvider;
use App\Providers\DocumentsServiceProvider;
use App\Providers\FleetServiceProvider;
use App\Providers\GatewayBoostServiceProvider;
use App\Providers\GatewayReleasesServiceProvider;
use App\Providers\TasksServiceProvider;
use App\Providers\TaskVmServiceProvider;

return [
    ApplicationServiceProvider::class,
    DocumentsServiceProvider::class,
    FleetServiceProvider::class,
    GatewayBoostServiceProvider::class,
    GatewayReleasesServiceProvider::class,
    TasksServiceProvider::class,
    TaskVmServiceProvider::class,
];
