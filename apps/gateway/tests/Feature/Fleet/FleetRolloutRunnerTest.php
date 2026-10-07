<?php

declare(strict_types=1);

use App\Actions\Fleet\ResumeFleetRolloutAction;
use App\Domain\Fleet\FleetNodeOutcome;
use App\Domain\Fleet\FleetRolloutRunner;
use App\Domain\Fleet\FleetRolloutStatus;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Nodes\RoleName;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

/** @return array<string, string> */
function fleetOutcomes(?FleetRollout $rollout = null): array
{
    $rollout ??= FleetRollout::query()->latest('id')->firstOrFail();

    return $rollout->nodes()->get()->mapWithKeys(static fn (FleetRolloutNode $row): array => [$row->node_name => $row->outcome->value])->all();
}

function fleetRun(): array
{
    return app(FleetRolloutRunner::class)->run();
}

describe('fleet rollout', function (): void {
    it('visits the rollout set one Node at a time, lowest risk first, and completes', function (): void {
        ['visitor' => $visitor] = FleetFixtures::bind();
        FleetFixtures::node('gateway', [RoleName::Gateway, RoleName::Vpn]);
        FleetFixtures::node('prod', [RoleName::AppProd]);
        FleetFixtures::node('db', [RoleName::Database]);
        FleetFixtures::node('dev', [RoleName::AppDev]);
        FleetFixtures::node('mixed', [RoleName::AppDev, RoleName::Metrics]);
        FleetFixtures::node('operator');

        $summary = fleetRun();
        $rollout = FleetRollout::query()->sole();

        expect($visitor->visited)->toBe(['dev', 'mixed', 'db', 'prod'])
            ->and($summary['status'])->toBe('completed')
            ->and($rollout->status)->toBe(FleetRolloutStatus::Completed)
            ->and($rollout->commit)->toBe(FleetFixtures::Commit)
            ->and($rollout->desired_state['cli']['version'])->toBe('0.4681.0')
            ->and($rollout->desired_state['agent']['version'])->toBe(NodeAgentFootprint::Version)
            ->and(array_keys($rollout->desired_state['footprints']))->toBe(['dev', 'mixed', 'db', 'prod'])
            ->and(fleetOutcomes())->toBe(['dev' => 'converged', 'mixed' => 'converged', 'db' => 'converged', 'prod' => 'converged']);
    });

    it('follows the operator order override and keeps the default order for the rest', function (): void {
        ['visitor' => $visitor] = FleetFixtures::bind();
        Config::set('fleet.order', ['prod', 'db']);
        FleetFixtures::node('dev', [RoleName::AppDev]);
        FleetFixtures::node('db', [RoleName::Database]);
        FleetFixtures::node('prod', [RoleName::AppProd]);
        FleetFixtures::node('metrics', [RoleName::Metrics]);

        fleetRun();

        expect($visitor->visited)->toBe(['prod', 'db', 'dev', 'metrics']);
    });

    it('stores the desired state on the verified release record of the commit', function (): void {
        FleetFixtures::bind();
        FleetFixtures::node('dev', [RoleName::AppDev]);
        $release = GatewayRelease::query()->create([
            'release_id' => substr(FleetFixtures::Commit, 0, 12), 'sha' => FleetFixtures::Commit, 'trigger' => 'auto',
            'outcome' => 'verified', 'phases' => ['verify' => ['outcome' => 'passed', 'status' => 'ok', 'version' => FleetFixtures::Commit]], 'duration_ms' => 1,
        ]);

        fleetRun();

        expect(FleetRollout::query()->sole()->gateway_release_id)->toBe($release->id);
    });

    it('skips an unreachable Node, continues, and leaves it for the catch-up', function (): void {
        ['visitor' => $visitor] = FleetFixtures::bind();
        FleetFixtures::node('dev', [RoleName::AppDev]);
        FleetFixtures::node('prod', [RoleName::AppProd]);
        $visitor->outcomes['dev'] = FleetNodeOutcome::Unreachable;

        expect(fleetRun()['status'])->toBe('completed')
            ->and(fleetOutcomes())->toBe(['dev' => 'unreachable', 'prod' => 'converged']);

        $visitor->visited = [];
        unset($visitor->outcomes['dev']);
        fleetRun();

        expect($visitor->visited)->toBe(['dev'])
            ->and(fleetOutcomes())->toBe(['dev' => 'converged', 'prod' => 'converged']);
    });

    it('halts at a failed Node, leaves the rest untouched, alerts once, and blocks later runs', function (): void {
        ['visitor' => $visitor, 'alerts' => $alerts] = FleetFixtures::bind();
        $dev = FleetFixtures::node('dev', [RoleName::AppDev]);
        FleetFixtures::node('prod', [RoleName::AppProd]);
        $visitor->outcomes['dev'] = FleetNodeOutcome::Failed;

        $summary = fleetRun();
        $rollout = FleetRollout::query()->sole();

        expect($summary['status'])->toBe('halted')
            ->and($visitor->visited)->toBe(['dev'])
            ->and(fleetOutcomes())->toBe(['dev' => 'failed', 'prod' => 'pending'])
            ->and($rollout->status)->toBe(FleetRolloutStatus::Halted)
            ->and($rollout->halted_node_id)->toBe($dev->id)
            ->and($rollout->error_code)->toBe('fleet.self_update_failed')
            ->and($rollout->alert['kind'])->toBe('rollout_halted')
            ->and($rollout->alert['activity_id'])->toBe(7)
            ->and($alerts->alerts)->toHaveCount(1)
            ->and($alerts->alerts[0]->kind)->toBe(ReleaseAlertKind::RolloutHalted)
            ->and($alerts->alerts[0]->subject->target)->toBe('fleet')
            ->and($alerts->alerts[0]->subject->sha)->toBe(FleetFixtures::Commit)
            ->and($rollout->nodes()->where('node_name', 'dev')->sole()->evidence)->toBe(['exit_code' => 1]);

        $visitor->visited = [];

        expect(fleetRun()['status'])->toBe('halted')
            ->and($visitor->visited)->toBe([])
            ->and($alerts->alerts)->toHaveCount(1);
    });

    it('resumes a halted rollout with the failed Node first', function (): void {
        ['visitor' => $visitor, 'units' => $units] = FleetFixtures::bind();
        FleetFixtures::node('dev', [RoleName::AppDev]);
        FleetFixtures::node('prod', [RoleName::AppProd]);
        $visitor->outcomes['dev'] = FleetNodeOutcome::Failed;
        fleetRun();
        unset($visitor->outcomes['dev']);
        $visitor->visited = [];

        $status = app(ResumeFleetRolloutAction::class)->execute(null);

        expect($status->rollout?->status)->toBe('running')
            ->and($units->started)->toBe(1)
            ->and(fleetOutcomes())->toBe(['dev' => 'pending', 'prod' => 'pending']);

        fleetRun();

        expect($visitor->visited)->toBe(['dev', 'prod'])
            ->and(FleetRollout::query()->sole()->status)->toBe(FleetRolloutStatus::Completed)
            ->and(fleetOutcomes())->toBe(['dev' => 'converged', 'prod' => 'converged']);
    });

    it('resumes past a skipped Node and keeps it skipped', function (): void {
        ['visitor' => $visitor] = FleetFixtures::bind();
        FleetFixtures::node('dev', [RoleName::AppDev]);
        FleetFixtures::node('prod', [RoleName::AppProd]);
        $visitor->outcomes['dev'] = FleetNodeOutcome::Failed;
        fleetRun();
        $visitor->visited = [];

        app(ResumeFleetRolloutAction::class)->execute('dev');
        fleetRun();
        fleetRun();

        expect($visitor->visited)->toBe(['prod'])
            ->and(fleetOutcomes())->toBe(['dev' => 'skipped', 'prod' => 'converged'])
            ->and(FleetRollout::query()->sole()->status)->toBe(FleetRolloutStatus::Completed);
    });

    it('refuses to resume without a halted rollout or for a Node outside it', function (): void {
        ['visitor' => $visitor] = FleetFixtures::bind();
        FleetFixtures::node('dev', [RoleName::AppDev]);

        expect(fn () => app(ResumeFleetRolloutAction::class)->execute(null))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('fleet.rollout_not_halted'));

        $visitor->outcomes['dev'] = FleetNodeOutcome::Failed;
        fleetRun();

        expect(fn () => app(ResumeFleetRolloutAction::class)->execute('ghost'))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('fleet.node_not_in_rollout'));
    });

    it('opens a rollout of the newest state when the Gateway moved on since the halt, carrying the skip', function (): void {
        ['visitor' => $visitor] = FleetFixtures::bind();
        FleetFixtures::node('dev', [RoleName::AppDev]);
        FleetFixtures::node('prod', [RoleName::AppProd]);
        $visitor->outcomes['dev'] = FleetNodeOutcome::Failed;
        fleetRun();
        $halted = FleetRollout::query()->sole();
        Config::set('app.version', str_repeat('b', 40));

        app(ResumeFleetRolloutAction::class)->execute('dev');
        $newest = FleetRollout::query()->latest('id')->firstOrFail();

        expect($halted->refresh()->status)->toBe(FleetRolloutStatus::Superseded)
            ->and($newest->commit)->toBe(str_repeat('b', 40))
            ->and(fleetOutcomes($newest))->toBe(['dev' => 'skipped', 'prod' => 'pending']);
    });

    it('waits without halting while the CLI release is not published, then rolls out', function (): void {
        ['visitor' => $visitor, 'catalog' => $catalog] = FleetFixtures::bind(published: false);
        FleetFixtures::node('dev', [RoleName::AppDev]);

        expect(fleetRun()['status'])->toBe('waiting')
            ->and($visitor->visited)->toBe([])
            ->and(FleetRollout::query()->sole()->status)->toBe(FleetRolloutStatus::Waiting);

        $catalog->available = true;
        Cache::flush();

        expect(fleetRun()['status'])->toBe('completed')
            ->and($visitor->visited)->toBe(['dev'])
            ->and(FleetRollout::query()->sole()->desired_state['cli']['status'])->toBe('available');
    });

    it('catches up only Nodes that lag: new, drifted footprint, or another agent version', function (): void {
        $artifact = new FakeFootprintArtifact('caddy', 'digest-1');
        ['visitor' => $visitor] = FleetFixtures::bind([$artifact]);
        $dev = FleetFixtures::node('dev', [RoleName::AppDev]);
        FleetFixtures::node('prod', [RoleName::AppProd]);
        $db = FleetFixtures::node('db', [RoleName::Database]);
        fleetRun();
        $visitor->visited = [];

        fleetRun();
        expect($visitor->visited)->toBe([]);

        FleetFixtures::node('new', [RoleName::AppDev]);
        app(AgentReportedVersions::class)->record($db->id, '0.2.0');
        $artifact->digest = 'digest-2';
        app(NodeFootprint::class)->converge($dev);

        fleetRun();

        expect($visitor->visited)->toBe(['new', 'db', 'prod']);
    });

    it('does nothing while the rollout is off', function (): void {
        ['visitor' => $visitor] = FleetFixtures::bind();
        Config::set('fleet.rollout', false);
        FleetFixtures::node('dev', [RoleName::AppDev]);

        expect(fleetRun()['status'])->toBe('disabled')
            ->and($visitor->visited)->toBe([])
            ->and(FleetRollout::query()->count())->toBe(0);
    });

    it('does nothing when the Gateway version is not a commit', function (): void {
        ['visitor' => $visitor] = FleetFixtures::bind();
        Config::set('app.version', 'dev');
        FleetFixtures::node('dev', [RoleName::AppDev]);

        expect(fleetRun()['status'])->toBe('commit_unknown')
            ->and($visitor->visited)->toBe([]);
    });
});

it('neither halts nor alerts when a Node waits for the CLI release', function (): void {
    ['visitor' => $visitor, 'alerts' => $alerts] = FleetFixtures::bind();
    FleetFixtures::node('dev', [RoleName::AppDev]);
    FleetFixtures::node('prod', [RoleName::AppProd]);
    $visitor->outcomes['dev'] = FleetNodeOutcome::Waiting;

    expect(fleetRun()['status'])->toBe('completed')
        ->and(fleetOutcomes())->toBe(['dev' => 'waiting', 'prod' => 'converged'])
        ->and($alerts->alerts)->toBe([]);

    $visitor->visited = [];
    unset($visitor->outcomes['dev']);
    fleetRun();

    expect($visitor->visited)->toBe(['dev'])
        ->and(fleetOutcomes())->toBe(['dev' => 'converged', 'prod' => 'converged']);
});

it('alerts once without halting when self-update stays incomplete for 6 visits', function (): void {
    ['visitor' => $visitor, 'alerts' => $alerts] = FleetFixtures::bind();
    FleetFixtures::node('dev', [RoleName::AppDev]);
    $visitor->outcomes['dev'] = FleetNodeOutcome::Waiting;
    $visitor->waitingCode = 'fleet.self_update_incomplete';

    foreach (range(1, 7) as $visit) {
        expect(fleetRun()['status'])->not->toBe('halted');
    }

    $row = FleetRollout::query()->sole()->nodes()->sole();

    expect($visitor->visited)->toHaveCount(7)
        ->and($row->evidence['incomplete_visits'])->toBe(7)
        ->and($row->evidence['stalled_alert']['kind'])->toBe('rollout_stalled')
        ->and($alerts->alerts)->toHaveCount(1)
        ->and($alerts->alerts[0]->kind)->toBe(ReleaseAlertKind::RolloutStalled);
});
