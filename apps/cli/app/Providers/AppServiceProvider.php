<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\GatewayConfigRepository;
use App\Services\Dns\LocalResolver;
use App\Services\Dns\ResolvesLocalDns;
use App\Services\Extensions\LocalExtensionState;
use App\Services\Git\GitRegistrationDiscovery;
use App\Services\Git\NativeGitRegistrationDiscovery;
use App\Services\Profile\CurlProfileRequestProfiler;
use App\Services\Profile\ProfileRequestProfiler;
use App\Support\Console\StandardInput;
use App\Support\Console\StandardInputReader;
use App\Support\Realtime\StreamWebSocketTransport;
use App\Support\Realtime\WebSocketTransport;
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
        $this->app->singleton(StandardInputReader::class, StandardInput::class);
        $this->app->singleton(ProfileRequestProfiler::class, CurlProfileRequestProfiler::class);
        $this->app->bind(WebSocketTransport::class, StreamWebSocketTransport::class);
        $this->app->singleton(ResolvesLocalDns::class, LocalResolver::class);
        $this->app->singleton(GitRegistrationDiscovery::class, NativeGitRegistrationDiscovery::class);
        $this->app->singleton(
            LocalExtensionState::class,
            static fn (): LocalExtensionState => new LocalExtensionState(
                rtrim((string) config('orbit.home'), '/').'/extensions.json',
            ),
        );

        $this->app->singleton(
            GatewayConfigRepository::class,
            static fn (): GatewayConfigRepository => new GatewayConfigRepository(
                rtrim(string: (string) config('orbit.home'), characters: '/').'/config.json',
            ),
        );
    }
}
