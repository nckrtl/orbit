<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Domain\AgentView\AgentViewConverger;
use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\GatewayReleases\GatewayDocumentCleanup;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseSmoke;
use App\Domain\GatewayReleases\GatewayReleaseUnitConverger;
use App\Domain\GatewayReleases\GatewayReleaseUnitStarter;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GitHub\GreenCommitResolver;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Settings\SettingRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Gateway\GatewayApplicationPath;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\GatewayReleases\ActionGatewayDocumentCleanup;
use App\Infrastructure\GatewayReleases\ArtisanGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\GatewayCleanupHandoff;
use App\Infrastructure\GatewayReleases\GatewayReleaseAdopter;
use App\Infrastructure\GatewayReleases\GatewayReleaseAlerts;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseExchange;
use App\Infrastructure\GatewayReleases\GatewayReleaseGuard;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Infrastructure\GatewayReleases\GatewayReleaseSource;
use App\Infrastructure\GatewayReleases\GatewayReleaseSupersession;
use App\Infrastructure\GatewayReleases\GatewayReleaseSwitcher;
use App\Infrastructure\GatewayReleases\GatewayRuntimeHandoff;
use App\Infrastructure\GatewayReleases\GatewaySchedulerHandoff;
use App\Infrastructure\GatewayReleases\GitHubArtifactWebBuild;
use App\Infrastructure\GatewayReleases\HttpGatewayReleaseVerifier;
use App\Infrastructure\GatewayReleases\LocalGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\NativeGatewayReleaseUnitConverger;
use App\Infrastructure\GatewayReleases\ScriptGatewayReleaseSmoke;
use App\Infrastructure\GatewayReleases\SqliteGatewayReleaseDatabase;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseUnitStarter;
use App\Infrastructure\GitHub\GitHubActionsReader;
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
        $this->app->bind(
            GatewayReleaseWebBuild::class,
            static fn (Application $app): GitHubArtifactWebBuild => new GitHubArtifactWebBuild(
                layout: $app->make(GatewayReleaseLayout::class),
                processes: $app->make(ProcessRunner::class),
                actions: $app->make(GitHubActionsReader::class),
                webRoot: rtrim(Config::string('orbit.gateway_web'), '/'),
            ),
        );
        $this->app->bind(
            ScriptGatewayReleaseSmoke::class,
            static fn (Application $app): ScriptGatewayReleaseSmoke => new ScriptGatewayReleaseSmoke(
                layout: $app->make(GatewayReleaseLayout::class),
                processes: $app->make(ProcessRunner::class),
                origin: rtrim(Config::string('orbit.gateway_verify_origin'), '/'),
                webRoot: rtrim(Config::string('orbit.gateway_web'), '/'),
                timeoutSeconds: Config::integer('orbit.gateway_release_smoke_timeout'),
                writeCheckProject: Config::string('orbit.gateway_release_smoke_project'),
            ),
        );
        $this->app->bind(GatewayReleaseSmoke::class, ScriptGatewayReleaseSmoke::class);
        $this->app->bind(
            GatewayReleaseDatabase::class,
            static fn (Application $app): SqliteGatewayReleaseDatabase => new SqliteGatewayReleaseDatabase(
                processes: $app->make(ProcessRunner::class),
                orbitHome: rtrim(Config::string('orbit.home'), '/'),
                keptSnapshots: max(1, Config::integer('orbit.gateway_releases.snapshots_keep')),
                minimumFreeBytes: max(0, Config::integer('orbit.gateway_releases.min_free_mb')) * 1_048_576,
                stepLock: self::stepLock(),
            ),
        );
        $this->app->bind(
            GatewayReleaseRuntime::class,
            static fn (Application $app): ArtisanGatewayReleaseRuntime => new ArtisanGatewayReleaseRuntime(
                layout: $app->make(GatewayReleaseLayout::class),
                processes: $app->make(ProcessRunner::class),
                // The scheduler drain, a forced stop's wait for the tick lock, and the rest of the handoff.
                timeout: (float) (self::drainSeconds() + 330 + 600),
                stepLock: self::stepLock(),
                fallback: new LocalGatewayReleaseRuntime($app->make(GatewayRuntimeHandoff::class)),
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
                releaseUnits: $app->make(GatewayReleaseUnitConverger::class),
                scheduler: new GatewaySchedulerHandoff(
                    processes: $app->make(ProcessRunner::class),
                    applicationPath: GatewayApplicationPath::resolve(),
                    drainSeconds: self::drainSeconds(),
                ),
                cleanup: new GatewayCleanupHandoff($app->make(GatewayDocumentCleanup::class)),
                applicationPath: GatewayApplicationPath::resolve(),
                orbitHome: rtrim(Config::string('orbit.home'), '/'),
                fleet: $app->make(FleetConvergeUnits::class),
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
                stepLock: self::stepLock(),
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
        $this->app->bind(
            GatewayReleaseSource::class,
            static fn (Application $app): GatewayReleaseSource => new GatewayReleaseSource(
                processes: $app->make(ProcessRunner::class),
                branch: Config::string('orbit.gateway_releases.branch'),
                checkName: Config::string('orbit.gateway_releases.check'),
            ),
        );
        $this->app->bind(
            GatewayReleaseSupersession::class,
            static fn (Application $app): GatewayReleaseSupersession => new GatewayReleaseSupersession(
                source: $app->make(GatewayReleaseSource::class),
                resolver: $app->make(GreenCommitResolver::class),
            ),
        );
        $this->app->bind(
            GatewayReleaseRecorder::class,
            static fn (Application $app): GatewayReleaseRecorder => new GatewayReleaseRecorder(
                alerts: $app->make(GatewayReleaseAlerts::class),
                automation: $app->make(GatewayReleaseAutomation::class),
                units: $app->make(GatewayReleaseUnitStarter::class),
                retry: $app->make(GatewayReleaseRetry::class),
            ),
        );
        $this->app->bind(
            GatewayReleaseAutomation::class,
            static fn (Application $app): GatewayReleaseAutomation => new GatewayReleaseAutomation(
                settings: $app->make(SettingRepository::class),
                pauseMarker: rtrim(Config::string('orbit.home'), '/').'/gateway-release.paused',
            ),
        );
        $this->app->bind(
            GatewayReleaseUnitConverger::class,
            static fn (Application $app): NativeGatewayReleaseUnitConverger => new NativeGatewayReleaseUnitConverger($app->make(ProcessRunner::class)),
        );
        $this->app->bind(
            GatewayReleaseUnitStarter::class,
            static fn (Application $app): SystemdGatewayReleaseUnitStarter => new SystemdGatewayReleaseUnitStarter($app->make(ProcessRunner::class)),
        );
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
                retry: $app->make(GatewayReleaseRetry::class),
                guard: $app->make(GatewayReleaseGuard::class),
                fleet: $app->make(FleetConvergeUnits::class),
            ),
        );
        $this->app->bind(
            GatewayReleaseAdopter::class,
            static fn (Application $app): GatewayReleaseAdopter => new GatewayReleaseAdopter(
                layout: $app->make(GatewayReleaseLayout::class),
                processes: $app->make(ProcessRunner::class),
                builder: $app->make(GatewayReleaseBuilder::class),
                exchange: new GatewayReleaseExchange($app->make(ProcessRunner::class)),
                runtime: new LocalGatewayReleaseRuntime($app->make(GatewayRuntimeHandoff::class)),
                verifier: $app->make(GatewayReleaseVerifier::class),
                recorder: $app->make(GatewayReleaseRecorder::class),
                guard: $app->make(GatewayReleaseGuard::class),
                deploy: $app->make(DeployGatewayReleaseAction::class),
            ),
        );
    }

    private static function drainSeconds(): int
    {
        return max(0, Config::integer('orbit.gateway_releases.scheduler_drain_seconds'));
    }

    private static function stepLock(): string
    {
        return rtrim(Config::string('orbit.home'), '/').'/gateway-release-step.lock';
    }
}
