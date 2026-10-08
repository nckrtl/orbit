<?php

declare(strict_types=1);

use App\Actions\Doctor\NodeDoctorProbe;
use App\Actions\GatewayReleases\ShowGatewayReleaseAction;
use App\Data\Fleet\DesiredCliReleaseData;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetNodeOutcome;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Fleet\FleetRolloutRunner;
use App\Domain\Fleet\NodeCliConvergence;
use App\Domain\Nodes\NodeCliInstallation;
use App\Domain\Nodes\NodeCliInstaller;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\ProxyCli\ProxyCliSourcePublisher;
use App\Models\FleetRolloutNode;
use App\Models\GatewayRelease;
use App\Models\Node;
use Illuminate\Support\Facades\Config;
use Tests\Support\Fleet\FleetFixtures;

final class RecordingCliInstaller implements NodeCliInstaller
{
    /** @var list<string> */
    public array $installed = [];

    public string $inspected = 'present';

    public function inspect(Node $node): string
    {
        return $this->inspected;
    }

    public function ensure(Node $node, DesiredCliReleaseData $release): NodeCliInstallation
    {
        $this->installed[] = $node->name.'@'.$release->version;

        return new NodeCliInstallation(NodeCliInstallation::Installed, true, $release->version);
    }
}

function fleetLagIssue(Node $node): ?array
{
    $report = new NodeDoctorProbe()->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'Linux', 'x86_64', true, true, true, true, true, null)));

    foreach ($report->issues as $issue) {
        if ($issue->code === 'node.release_lag') {
            return ['expected' => $issue->expected, 'observed' => $issue->observed];
        }
    }

    return null;
}

describe('fleet integration', function (): void {
    it('reports node.release_lag until the Node runs the desired state, and only while the rollout is on', function (): void {
        ['visitor' => $visitor] = FleetFixtures::bind();
        $dev = FleetFixtures::node('dev', [RoleName::AppDev]);
        $operator = FleetFixtures::node('operator');

        expect(fleetLagIssue($dev))->toBeNull();
        app(DesiredFleetState::class)->current();

        expect(fleetLagIssue($dev))->toBe(['expected' => substr(FleetFixtures::Commit, 0, 12), 'observed' => 'no rollout yet'])
            ->and(fleetLagIssue($operator))->toBeNull();

        $visitor->outcomes['dev'] = FleetNodeOutcome::Unreachable;
        app(FleetRolloutRunner::class)->run();
        expect(fleetLagIssue($dev)['observed'] ?? null)->toBe('unreachable');

        unset($visitor->outcomes['dev']);
        app(FleetRolloutRunner::class)->run();
        expect(fleetLagIssue($dev))->toBeNull();

        Config::set('fleet.rollout', false);
        FleetRolloutNode::query()->update(['outcome' => FleetNodeOutcome::Pending]);
        expect(fleetLagIssue($dev))->toBeNull();
    });

    it('reports no node.release_lag for a task sandbox and installs no CLI on it', function (): void {
        FleetFixtures::bind();
        $installer = new RecordingCliInstaller;
        app()->instance(NodeCliInstaller::class, $installer);
        $dev = FleetFixtures::node('dev', [RoleName::AppDev]);
        $sandbox = FleetFixtures::sandbox();
        app(DesiredFleetState::class)->current();

        app(NodeCliConvergence::class)->converge($sandbox);

        expect(fleetLagIssue($sandbox))->toBeNull()
            ->and(fleetLagIssue($dev)['observed'] ?? null)->toBe('no rollout yet')
            ->and($installer->installed)->toBe([]);
    });

    it('shows the fleet rollout of a release on gateway:release:show', function (): void {
        FleetFixtures::bind();
        FleetFixtures::node('dev', [RoleName::AppDev]);
        GatewayRelease::query()->create([
            'release_id' => substr(FleetFixtures::Commit, 0, 12), 'sha' => FleetFixtures::Commit, 'trigger' => 'auto',
            'outcome' => 'verified', 'phases' => ['verify' => ['outcome' => 'passed', 'status' => 'ok', 'version' => FleetFixtures::Commit]], 'duration_ms' => 1,
        ]);
        app(FleetRolloutRunner::class)->run();

        $shown = app(ShowGatewayReleaseAction::class)->execute(substr(FleetFixtures::Commit, 0, 12));

        expect($shown['fleet_rollout']['status'])->toBe('completed')
            ->and($shown['fleet_rollout']['nodes'][0]['node'])->toBe('dev')
            ->and($shown['fleet_rollout']['desired_state']['cli']['version'])->toBe('0.4681.0');
    });

    it('installs the CLI in provisioning and role converge only on a Node of the rollout set with a published release', function (): void {
        FleetFixtures::bind();
        $installer = new RecordingCliInstaller;
        app()->instance(NodeCliInstaller::class, $installer);
        $dev = FleetFixtures::node('dev', [RoleName::AppDev]);
        $operator = FleetFixtures::node('operator');
        $gateway = FleetFixtures::node('gateway', [RoleName::Gateway]);

        app(NodeCliConvergence::class)->converge($dev);
        app(NodeCliConvergence::class)->converge($operator);
        app(NodeCliConvergence::class)->converge($gateway);

        expect($installer->installed)->toBe(['dev@0.4681.0']);
    });

    it('skips the CLI install while the release is pending', function (): void {
        FleetFixtures::bind(published: false);
        $installer = new RecordingCliInstaller;
        app()->instance(NodeCliInstaller::class, $installer);

        expect(app(NodeCliConvergence::class)->converge(FleetFixtures::node('dev', [RoleName::AppDev])))->toBeNull()
            ->and($installer->installed)->toBe([]);
    });

    it('says whether the ProxyCli collector script changed', function (): void {
        $script = $this->app->make(ProxyCliSourcePublisher::class)->command("print('x')\n");

        expect((string) stream_get_contents($script->protectedInput?->stream() ?? fopen('php://memory', 'r')))
            ->toContain('echo '.ProxyCliSourcePublisher::Unchanged)
            ->toContain('echo '.ProxyCliSourcePublisher::Published);
    });
});

it('reports node.cli_foreign for a Node whose CLI Orbit did not install, and marks it during role converge', function (): void {
    FleetFixtures::bind();
    $installer = new class implements NodeCliInstaller
    {
        public function inspect(Node $node): string
        {
            return 'foreign';
        }

        public function ensure(Node $node, DesiredCliReleaseData $release): NodeCliInstallation
        {
            throw new ResourceOperationException('cli.foreign_binary', 'wrapper', 409);
        }
    };
    app()->instance(NodeCliInstaller::class, $installer);
    $beast = FleetFixtures::node('beast', [RoleName::AppDev]);

    app(NodeCliConvergence::class)->converge($beast);
    $report = new NodeDoctorProbe()->inspect(new DoctorNodeContext($beast, new NodeInspectionData(true, 'Linux', 'x86_64', true, true, true, true, true, null)));

    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))->toContain('node.cli_foreign')
        ->and(app(FleetRolloutMembership::class)->exclusion($beast->fresh()->load('roles')))->toBe('foreign_cli');
});
