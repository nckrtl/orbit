<?php

declare(strict_types=1);

use App\Actions\Fleet\ConvergeNodeFootprintAction;
use App\Actions\Fleet\ResumeFleetRolloutAction;
use App\Actions\Fleet\ShowFleetRolloutAction;
use App\Domain\Fleet\FleetNodeConverger;
use App\Domain\Fleet\FleetNodeVerifier;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\FleetRolloutAlerts;
use App\Domain\Fleet\FleetRolloutPlanner;
use App\Domain\Fleet\FleetRolloutRunner;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Fleet\NodeCliConvergence;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Infrastructure\Fleet\Footprint\AgentFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\AnnotatorFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\CaddyFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\CaddyPackageFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\PrivateDnsFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\ProxyCliFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\RouteResidueFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;
use App\Infrastructure\Fleet\NativeFleetConvergeUnits;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayRuntimeHandoff;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Nodes\NodeUpdateLock;
use App\Infrastructure\Nodes\SshNodeCliInstaller;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Fleet\FleetFixtures;

/** Reads a private dependency of a container-built object, so a nullable one the container left null shows. */
function fleetWired(object $object, string $property): mixed
{
    return new ReflectionProperty($object, $property)->getValue($object);
}

describe('fleet container wiring', function (): void {
    it('builds the rollout that orbit:fleet-converge runs from real implementations', function (): void {
        expect(Artisan::all())->toHaveKey('orbit:fleet-converge');

        $runner = app(FleetRolloutRunner::class);
        $converger = fleetWired($runner, 'converger');

        expect($converger)->toBeInstanceOf(FleetNodeConverger::class)
            ->and(fleetWired($runner, 'planner'))->toBeInstanceOf(FleetRolloutPlanner::class)
            ->and(fleetWired($runner, 'lag'))->toBeInstanceOf(FleetReleaseLag::class)
            ->and(fleetWired($runner, 'alerts'))->toBeInstanceOf(FleetRolloutAlerts::class)
            ->and(fleetWired($runner, 'units'))->toBeInstanceOf(NativeFleetConvergeUnits::class)
            ->and(fleetWired($runner, 'serving'))->toBeInstanceOf(FleetServingRelease::class)
            ->and(fleetWired(fleetWired($runner, 'alerts'), 'notifier'))->toBeInstanceOf(ReleaseAlertNotifier::class)
            ->and(fleetWired($converger, 'cli'))->toBeInstanceOf(SshNodeCliInstaller::class)
            ->and(fleetWired(fleetWired($converger, 'cli'), 'updateLock'))->toBeInstanceOf(NodeUpdateLock::class)
            ->and(fleetWired($converger, 'verifier'))->toBeInstanceOf(FleetNodeVerifier::class)
            ->and(fleetWired(fleetWired($converger, 'verifier'), 'versions'))->toBeInstanceOf(AgentReportedVersions::class)
            ->and(fleetWired($converger, 'updateLock'))->toBeInstanceOf(NodeUpdateLock::class)
            ->and(array_map(get_class(...), [...fleetWired(fleetWired($converger, 'footprint'), 'artifacts')]))->toBe([
                AgentFootprintArtifact::class,
                CaddyPackageFootprintArtifact::class,
                CaddyFootprintArtifact::class,
                PrivateDnsFootprintArtifact::class,
                ProxyCliFootprintArtifact::class,
                AnnotatorFootprintArtifact::class,
                RouteResidueFootprintArtifact::class,
                TmpfilesFootprintArtifact::class,
            ]);
    });

    it('wires node:converge, fleet:rollout:status, and fleet:rollout:resume', function (): void {
        $converge = app(ConvergeNodeFootprintAction::class);
        $resume = app(ResumeFleetRolloutAction::class);

        expect(fleetWired($converge, 'footprint'))->toBeInstanceOf(NodeFootprint::class)
            ->and(fleetWired($converge, 'updateLock'))->toBeInstanceOf(NodeUpdateLock::class)
            ->and(fleetWired($resume, 'units'))->toBeInstanceOf(NativeFleetConvergeUnits::class)
            ->and(fleetWired($resume, 'show'))->toBeInstanceOf(ShowFleetRolloutAction::class)
            ->and(app(ShowFleetRolloutAction::class))->toBeInstanceOf(ShowFleetRolloutAction::class);
    });

    it('starts the rollout after a verified release and installs its units in the handoff and gateway-web', function (): void {
        FleetFixtures::inPlace();

        expect(fleetWired(app(GatewayReleasePromoter::class), 'fleet'))->toBeInstanceOf(NativeFleetConvergeUnits::class)
            ->and(fleetWired(app(GatewayRuntimeHandoff::class), 'fleet'))->toBeInstanceOf(NativeFleetConvergeUnits::class)
            ->and(fleetWired(app(GatewayWebConverger::class), 'fleet'))->toBeInstanceOf(NativeFleetConvergeUnits::class);
    });

    it('takes the update lock in the agent converge and installs the CLI in provisioning', function (): void {
        // The test suite binds a fake agent runtime; the production binding names this class.
        $agent = app(NodeAgentSshExecutor::class);

        expect($agent)->toBeInstanceOf(NodeAgentSshExecutor::class)
            ->and(fleetWired($agent, 'updateLock'))->toBeInstanceOf(NodeUpdateLock::class)
            ->and(fleetWired(app(NodeCliConvergence::class), 'cli'))->toBeInstanceOf(SshNodeCliInstaller::class);
    });
});
