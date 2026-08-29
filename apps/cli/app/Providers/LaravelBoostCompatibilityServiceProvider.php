<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\LaravelZeroBoostManager;
use App\Support\LaravelZeroGuidelineAssist;
use App\Support\LaravelZeroGuidelineComposer;
use App\Support\LaravelZeroProjectManager;
use App\Support\LaravelZeroToolExecutor;
use Illuminate\Support\ServiceProvider;
use Laravel\Boost\BoostManager;
use Laravel\Boost\Install\GuidelineAssist;
use Laravel\Boost\Install\GuidelineComposer;
use Laravel\Boost\Mcp\ToolExecutor;
use Laravel\Roster\ProjectManager;

final class LaravelBoostCompatibilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BoostManager::class, LaravelZeroBoostManager::class);
        $this->app->singleton(GuidelineAssist::class, LaravelZeroGuidelineAssist::class);
        $this->app->singleton(GuidelineComposer::class, LaravelZeroGuidelineComposer::class);
        $this->app->singleton(ProjectManager::class, LaravelZeroProjectManager::class);
        $this->app->singleton(ToolExecutor::class, LaravelZeroToolExecutor::class);
    }
}
