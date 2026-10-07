<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\GatewayConfigRepository;
use App\Services\Dns\LocalResolver;
use App\Services\Dns\ResolvesLocalDns;
use App\Services\Git\GitRegistrationDiscovery;
use App\Services\Git\NativeGitRegistrationDiscovery;
use App\Services\Profile\CurlProfileRequestProfiler;
use App\Services\Profile\ProfileRequestProfiler;
use App\Services\SelfUpdate\CliReleaseNotice;
use App\Services\SelfUpdate\NativeSelfUpdateHost;
use App\Services\SelfUpdate\SelfUpdateHost;
use App\Support\Console\StandardInput;
use App\Support\Console\StandardInputReader;
use App\Support\Logs\LogFollowClock;
use App\Support\Logs\SystemLogFollowClock;
use App\Support\OrbitHome;
use App\Support\Realtime\StreamWebSocketTransport;
use App\Support\Realtime\WebSocketTransport;
use Design\Support\FixtureReplay;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Dev-only: replay recorded Gateway responses under a real command; see design/README.md.
        $fixtures = getenv('ORBIT_GATEWAY_FIXTURES');
        if (getenv('ORBIT_DESIGN') === '1' && is_string($fixtures) && $fixtures !== '' && class_exists(FixtureReplay::class)) {
            FixtureReplay::install($fixtures, OrbitHome::path());
        }
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            HttpFactory::class,
            fn (): HttpFactory => new HttpFactory($this->app->bound('events') ? $this->app->make(Dispatcher::class) : null),
        );
        $this->app->singleton(StandardInputReader::class, StandardInput::class);
        $this->app->singleton(ProfileRequestProfiler::class, CurlProfileRequestProfiler::class);
        $this->app->bind(WebSocketTransport::class, StreamWebSocketTransport::class);
        $this->app->bind(LogFollowClock::class, SystemLogFollowClock::class);
        $this->app->singleton(ResolvesLocalDns::class, LocalResolver::class);
        $this->app->singleton(GitRegistrationDiscovery::class, NativeGitRegistrationDiscovery::class);
        $this->app->singleton(
            CliReleaseNotice::class,
            static fn (): CliReleaseNotice => new CliReleaseNotice(
                statePath: OrbitHome::path().'/'.CliReleaseNotice::StateFile,
                currentVersion: is_string($version = config('app.version')) ? $version : '',
            ),
        );
        $this->app->bind(
            SelfUpdateHost::class,
            static fn (): NativeSelfUpdateHost => new NativeSelfUpdateHost(
                agentUnitPath: Config::string('orbit.self_update.agent_unit'),
                agentBinaryPath: Config::string('orbit.self_update.agent_binary'),
            ),
        );
        $this->app->singleton(
            GatewayConfigRepository::class,
            static fn (): GatewayConfigRepository => new GatewayConfigRepository(
                OrbitHome::path().'/config.json',
            ),
        );
    }
}
