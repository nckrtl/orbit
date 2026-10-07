<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\AgentView\AgentViewConverger;
use App\Domain\GatewayReleases\GatewayDocumentCleanup;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseSmoke;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Gateway\GatewayApplicationPath;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\GatewayReleases\ActionGatewayDocumentCleanup;
use App\Infrastructure\GatewayReleases\ArtisanGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\GatewayCleanupHandoff;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseSwitcher;
use App\Infrastructure\GatewayReleases\GatewayRuntimeHandoff;
use App\Infrastructure\GatewayReleases\GatewaySchedulerHandoff;
use App\Infrastructure\GatewayReleases\HttpGatewayReleaseVerifier;
use App\Infrastructure\GatewayReleases\NoGatewayReleaseSmoke;
use App\Infrastructure\GatewayReleases\NoGatewayReleaseWebBuild;
use App\Infrastructure\GatewayReleases\SqliteGatewayReleaseDatabase;
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
        $this->app->bind(GatewayReleaseSmoke::class, NoGatewayReleaseSmoke::class);
        $this->app->bind(
            GatewayReleaseDatabase::class,
            static fn (Application $app): SqliteGatewayReleaseDatabase => new SqliteGatewayReleaseDatabase(
                processes: $app->make(ProcessRunner::class),
                orbitHome: rtrim(Config::string('orbit.home'), '/'),
                keptSnapshots: max(1, Config::integer('orbit.gateway_releases.snapshots_keep')),
                minimumFreeBytes: max(0, Config::integer('orbit.gateway_releases.min_free_mb')) * 1_048_576,
            ),
        );
        $this->app->bind(
            GatewayReleaseRuntime::class,
            static fn (Application $app): ArtisanGatewayReleaseRuntime => new ArtisanGatewayReleaseRuntime(
                layout: $app->make(GatewayReleaseLayout::class),
                processes: $app->make(ProcessRunner::class),
            ),
        );
        $this->app->bind(GatewayDocumentCleanup::class, ActionGatewayDocumentCleanup::class);
        $this->app->bind(
            GatewayRuntimeHandoff::class,
            static fn (Application $app): GatewayRuntimeHandoff => new GatewayRuntimeHandoff(
                builds: $app->make(NodeCaddyBuilds::class),
                fpmRenderer: $app->make(GatewayFpmConfigRenderer::class),
                fpm: new NativeGatewayFpmConverger($app->make(ProcessRunner::class)),
                files: $app->make(ProtectedFileWriter::class),
                hibernator: $app->make(RuntimeHibernatorConverger::class),
                agentView: $app->make(AgentViewConverger::class),
                scheduler: new GatewaySchedulerHandoff(
                    processes: $app->make(ProcessRunner::class),
                    applicationPath: GatewayApplicationPath::resolve(),
                ),
                cleanup: new GatewayCleanupHandoff($app->make(GatewayDocumentCleanup::class)),
                applicationPath: GatewayApplicationPath::resolve(),
                orbitHome: rtrim(Config::string('orbit.home'), '/'),
            ),
        );
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
                reservedBytes: static fn (): int => $app->make(GatewayReleaseDatabase::class)->snapshotBytes(),
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
                keptReleases: max(1, Config::integer('orbit.gateway_releases.keep')),
            ),
        );
    }
}
