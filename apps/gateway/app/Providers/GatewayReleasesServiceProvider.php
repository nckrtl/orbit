<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
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
        $this->app->bind(
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
            ),
        );
    }
}
