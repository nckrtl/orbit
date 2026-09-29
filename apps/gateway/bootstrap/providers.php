<?php

declare(strict_types=1);

use App\Providers\ApplicationServiceProvider;
use App\Providers\GatewayBoostServiceProvider;
use App\Providers\TasksServiceProvider;

return [
    ApplicationServiceProvider::class,
    GatewayBoostServiceProvider::class,
    TasksServiceProvider::class,
];
