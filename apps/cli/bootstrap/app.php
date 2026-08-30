<?php

declare(strict_types=1);

use App\Console\Kernel;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use LaravelZero\Framework\Application;

$app = Application::configure(basePath: dirname(__DIR__))->create();

$app->singleton(ConsoleKernel::class, Kernel::class);

return $app;
