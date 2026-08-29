<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\GatewayConfigRepository;
use App\Services\Dns\LocalResolver;
use App\Services\Dns\ResolvesLocalDns;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void {}

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ResolvesLocalDns::class, LocalResolver::class);

        $this->app->singleton(
            GatewayConfigRepository::class,
            static fn (): GatewayConfigRepository => new GatewayConfigRepository(
                rtrim(string: (string) config('orbit.home'), characters: '/').'/config.json',
            ),
        );
    }
}
