<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\GatewayBoostServiceProvider;
use App\Providers\TasksServiceProvider;

return [
    AppServiceProvider::class,
    GatewayBoostServiceProvider::class,
    TasksServiceProvider::class,
];
