<?php

declare(strict_types=1);

use App\Providers\ApplicationServiceProvider;
use App\Providers\DocumentsServiceProvider;
use App\Providers\GatewayBoostServiceProvider;
use App\Providers\TasksServiceProvider;

return [
    ApplicationServiceProvider::class,
    DocumentsServiceProvider::class,
    GatewayBoostServiceProvider::class,
    TasksServiceProvider::class,
];
