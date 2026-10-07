<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseSmoke;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseSwitcher;
use App\Infrastructure\GatewayReleases\HttpGatewayReleaseVerifier;
use App\Infrastructure\GatewayReleases\ListingGatewayReleaseDatabase;
use App\Infrastructure\GatewayReleases\NoGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\NoGatewayReleaseSmoke;
use App\Infrastructure\GatewayReleases\NoGatewayReleaseWebBuild;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

/** Wires the Gateway release pipeline ([Update and recover a Gateway](/reference/gateway-recovery#release-layout)). */
final class GatewayReleasesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GatewayReleaseLayout::class, static fn (): GatewayReleaseLayout => GatewayReleaseLayout::fromConfig());
        $this->app->bind(GatewayReleaseWebBuild::class, NoGatewayReleaseWebBuild::class);
        $this->app->bind(GatewayReleaseRuntime::class, NoGatewayReleaseRuntime::class);
        $this->app->bind(GatewayReleaseSmoke::class, NoGatewayReleaseSmoke::class);
        $this->app->bind(GatewayReleaseDatabase::class, ListingGatewayReleaseDatabase::class);
        $this->app->singleton(
            GatewayReleaseLock::class,
            static fn (): GatewayReleaseLock => new GatewayReleaseLock(rtrim(Config::string('orbit.home'), '/').'/gateway-release.lock'),
        );
        $this->app->bind(
            GatewayReleaseBuilder::class,
            static fn (Application $app): GatewayReleaseBuilder => new GatewayReleaseBuilder(
                layout: $app->make(GatewayReleaseLayout::class),
                processes: $app->make(ProcessRunner::class),
                readAccess: $app->make(RepositoryReadAccess::class),
                web: $app->make(GatewayReleaseWebBuild::class),
                minimumFreeBytes: max(0, Config::integer('orbit.gateway_releases.min_free_mb')) * 1_048_576,
            ),
        );
        $this->app->bind(
            GatewayReleaseVerifier::class,
            static fn (Application $app): HttpGatewayReleaseVerifier => new HttpGatewayReleaseVerifier(
                processes: $app->make(ProcessRunner::class),
                origin: rtrim(Config::string('orbit.gateway_verify_origin'), '/'),
            ),
        );
        $this->app->bind(
            GatewayReleaseSwitcher::class,
            static fn (Application $app): GatewayReleaseSwitcher => new GatewayReleaseSwitcher(
                $app->make(GatewayReleaseLayout::class),
                $app->make(ProcessRunner::class),
            ),
        );
        $this->app->bind(GatewayReleaseRecorder::class, GatewayReleaseRecorder::class);
        $this->app->bind(
            GatewayReleasePromoter::class,
            static fn (Application $app): GatewayReleasePromoter => new GatewayReleasePromoter(
                layout: $app->make(GatewayReleaseLayout::class),
                switcher: $app->make(GatewayReleaseSwitcher::class),
                runtime: $app->make(GatewayReleaseRuntime::class),
                verifier: $app->make(GatewayReleaseVerifier::class),
                web: $app->make(GatewayReleaseWebBuild::class),
                smoke: $app->make(GatewayReleaseSmoke::class),
                recorder: $app->make(GatewayReleaseRecorder::class),
                builder: $app->make(GatewayReleaseBuilder::class),
            ),
        );
    }
}
