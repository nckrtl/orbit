<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fleet\ShowFleetRolloutAction;
use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\Fleet\FleetNodeConverger;
use App\Domain\Fleet\FleetNodeVerification;
use App\Domain\Fleet\FleetNodeVerifier;
use App\Domain\Fleet\FleetNodeVisitor;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\FleetRolloutAlerts;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Fleet\FleetRolloutPlanner;
use App\Domain\Fleet\FleetRolloutRunner;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Fleet\ReleaseHistory;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeCliInstaller;
use App\Domain\Nodes\NodeUpdateBroadcaster;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\Fleet\Footprint\AgentFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\AnnotatorFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\CaddyFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\PrivateDnsFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\ProxyCliFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\RouteResidueFootprintArtifact;
use App\Infrastructure\Fleet\NativeFleetConvergeUnits;
use App\Infrastructure\Fleet\StaticCliRelease;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Nodes\SshNodeCliInstaller;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

/** The fleet rollout of ADR 0202: CLI install, footprint re-apply, the rollout, and its units. */
final class FleetServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->bind(NodeCliInstaller::class, SshNodeCliInstaller::class);
        $this->bindStaticCliRelease();
        $this->app->bind(FleetNodeVisitor::class, FleetNodeConverger::class);
        $this->app->bind(FleetNodeVerification::class, FleetNodeVerifier::class);
        $this->app->bind(
            FleetConvergeUnits::class,
            static fn (Application $app): NativeFleetConvergeUnits => new NativeFleetConvergeUnits($app->make(ProcessRunner::class)),
        );
        $this->app->bind(
            AgentReportedVersions::class,
            static fn (Application $app): AgentReportedVersions => new AgentReportedVersions(
                $app->make(CacheManager::class)->build(CacheAgentStateView::storeConfiguration(Config::string('orbit.home'))),
            ),
        );
        $this->app->bind(
            FleetRolloutMembership::class,
            static fn (Application $app): FleetRolloutMembership => new FleetRolloutMembership(
                eligibility: $app->make(ManagedNodeEligibility::class),
                servingHost: $app->make(GatewayServingHost::class),
                order: self::order(),
            ),
        );
        $this->app->bind(
            NodeFootprint::class,
            static fn (Application $app): NodeFootprint => new NodeFootprint([
                $app->make(AgentFootprintArtifact::class),
                $app->make(CaddyFootprintArtifact::class),
                $app->make(PrivateDnsFootprintArtifact::class),
                $app->make(ProxyCliFootprintArtifact::class),
                $app->make(AnnotatorFootprintArtifact::class),
                $app->make(RouteResidueFootprintArtifact::class),
            ]),
        );
        $this->app->bind(
            FleetRolloutRunner::class,
            static fn (Application $app): FleetRolloutRunner => new FleetRolloutRunner(
                desired: $app->make(DesiredFleetState::class),
                planner: $app->make(FleetRolloutPlanner::class),
                membership: $app->make(FleetRolloutMembership::class),
                converger: $app->make(FleetNodeVisitor::class),
                lag: $app->make(FleetReleaseLag::class),
                alerts: $app->make(FleetRolloutAlerts::class),
                locks: $app->make(NodeLocks::class),
                enabled: Config::boolean('fleet.rollout'),
                serving: $app->make(FleetServingRelease::class),
                units: $app->make(FleetConvergeUnits::class),
                nodeUpdates: $app->make(NodeUpdateBroadcaster::class),
            ),
        );
        $this->app->bind(
            FleetReleaseLag::class,
            static fn (Application $app): FleetReleaseLag => new FleetReleaseLag(
                desired: $app->make(DesiredFleetState::class),
                membership: $app->make(FleetRolloutMembership::class),
                footprint: $app->make(NodeFootprint::class),
                versions: $app->make(AgentReportedVersions::class),
                enabled: Config::boolean('fleet.rollout'),
            ),
        );
        $this->app->bind(
            ShowFleetRolloutAction::class,
            static fn (Application $app): ShowFleetRolloutAction => new ShowFleetRolloutAction(
                membership: $app->make(FleetRolloutMembership::class),
                desired: $app->make(DesiredFleetState::class),
                enabled: Config::boolean('fleet.rollout'),
            ),
        );
    }

    /** Test-only: a disposable topology names its CLI release in a local manifest ({@see StaticCliRelease}). */
    private function bindStaticCliRelease(): void
    {
        $path = Config::get('fleet.static_cli_release');

        if (! is_string($path) || $path === '') {
            return;
        }

        $release = new StaticCliRelease($path);
        $this->app->instance(ReleaseHistory::class, $release);
        $this->app->instance(CliReleaseCatalog::class, $release);
    }

    /** @return list<string> */
    private static function order(): array
    {
        $order = Config::get('fleet.order');

        return is_array($order) ? array_values(array_filter($order, is_string(...))) : [];
    }
}
