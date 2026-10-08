<?php

declare(strict_types=1);

use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetNodeOutcome;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Fleet\FleetRolloutRunner;
use App\Domain\Fleet\FleetRolloutStatus;
use App\Domain\Fleet\NodeCliState;
use App\Domain\Fleet\ReleaseHistory;
use App\Domain\Nodes\RoleName;
use App\Domain\Releases\ReleaseAlertKind;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use App\Models\GatewayRelease;
use App\Models\Node;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FakeReleaseHistory;
use Tests\Support\Fleet\FleetFixtures;

function safetyRun(): array
{
    return app(FleetRolloutRunner::class)->run();
}

function safetyRelease(string $commit): GatewayRelease
{
    return GatewayRelease::query()->create([
        'release_id' => substr($commit, 0, 12), 'sha' => $commit, 'trigger' => 'auto',
        'outcome' => 'verified', 'phases' => ['verify' => ['outcome' => 'passed', 'status' => 'ok', 'version' => $commit]], 'duration_ms' => 1,
    ]);
}

describe('fleet rollout gate', function (): void {
    it('rolls out nothing for a release-layout commit without a verified record, and rolls out once it is verified', function (): void {
        ['visitor' => $visitor] = FleetFixtures::bind();
        FleetFixtures::adopt(FleetFixtures::Commit);
        FleetFixtures::runFrom(FleetFixtures::Commit);
        FleetFixtures::node('dev', [RoleName::AppDev]);

        expect(safetyRun()['status'])->toBe('release_unverified')
            ->and($visitor->visited)->toBe([])
            ->and(FleetRollout::query()->count())->toBe(0);

        safetyRelease(FleetFixtures::Commit);

        expect(safetyRun()['status'])->toBe('completed')
            ->and($visitor->visited)->toBe(['dev'])
            // A verified release may downgrade the CLI after a Gateway rollback.
            ->and($visitor->downgrades)->toBe([true]);
    });

    it('never rolls out on adopt phase 1, whose verified record only saw the Gateway serve as dev', function (): void {
        ['visitor' => $visitor] = FleetFixtures::bind();
        FleetFixtures::adopt(FleetFixtures::Commit);
        FleetFixtures::runFrom(FleetFixtures::Commit);
        FleetFixtures::node('dev', [RoleName::AppDev]);
        GatewayRelease::query()->create([
            'release_id' => substr(FleetFixtures::Commit, 0, 12), 'sha' => FleetFixtures::Commit, 'trigger' => 'adopt',
            'outcome' => 'verified', 'phases' => ['verify' => ['outcome' => 'passed', 'status' => 'ok', 'version' => 'dev']], 'duration_ms' => 1,
        ]);

        expect(safetyRun()['status'])->toBe('release_unverified')
            ->and($visitor->visited)->toBe([])
            ->and(FleetRollout::query()->count())->toBe(0);

        // Phase 2 deploys the commit and verifies the exact version.
        safetyRelease(FleetFixtures::Commit);

        expect(safetyRun()['status'])->toBe('completed')
            ->and($visitor->visited)->toBe(['dev'])
            ->and(FleetRollout::query()->value('gateway_release_id'))->toBe(GatewayRelease::query()->max('id'));
    });

    it('fails closed when the release layout cannot be read', function (): void {
        ['visitor' => $visitor] = FleetFixtures::bind();
        Config::set('orbit.gateway_checkout', '/nonexistent/orbit/apps/gateway');
        FleetFixtures::node('dev', [RoleName::AppDev]);

        expect(safetyRun()['status'])->toBe('release_layout_unreadable')
            ->and($visitor->visited)->toBe([]);
    });

    it('never passes the downgrade consent for an in-place Gateway', function (): void {
        ['visitor' => $visitor] = FleetFixtures::bind();
        FleetFixtures::node('dev', [RoleName::AppDev]);

        safetyRun();

        expect($visitor->downgrades)->toBe([false]);
    });
});

describe('fleet rollout across a newer release', function (): void {
    it('rolls out nothing when this process resolved an older release than the one serving, and runs again later', function (): void {
        ['visitor' => $visitor, 'units' => $units] = FleetFixtures::bind();
        FleetFixtures::node('dev', [RoleName::AppDev]);
        FleetFixtures::adopt(FleetFixtures::Commit);
        FleetFixtures::runFrom(FleetFixtures::Commit);
        $newer = str_repeat('b', 40);
        FleetFixtures::adopt($newer);
        safetyRelease($newer);

        expect(safetyRun()['status'])->toBe('superseded')
            ->and($visitor->visited)->toBe([])
            ->and($units->startedLater)->toBe(1)
            ->and(FleetRollout::query()->count())->toBe(0);
    });

    it('stops before the next Node when a newer release goes current during the pass', function (): void {
        ['visitor' => $visitor, 'units' => $units] = FleetFixtures::bind();
        FleetFixtures::adopt(FleetFixtures::Commit);
        FleetFixtures::runFrom(FleetFixtures::Commit);
        safetyRelease(FleetFixtures::Commit);
        FleetFixtures::node('dev', [RoleName::AppDev]);
        FleetFixtures::node('prod', [RoleName::AppProd]);
        $visitor->afterVisit = static fn (Node $node) => FleetFixtures::adopt(str_repeat('b', 40));

        $summary = safetyRun();

        expect($summary['status'])->toBe('superseded')
            ->and($visitor->visited)->toBe(['dev'])
            ->and($units->startedLater)->toBe(1)
            ->and(FleetRollout::query()->sole()->nodes()->where('node_name', 'prod')->sole()->outcome)->toBe(FleetNodeOutcome::Pending);
    });
});

it('never restarts for a leftover APP_VERSION while it runs from the serving release', function (): void {
    ['visitor' => $visitor, 'units' => $units] = FleetFixtures::bind();
    FleetFixtures::adopt(FleetFixtures::Commit);
    FleetFixtures::runFrom(FleetFixtures::Commit);
    safetyRelease(FleetFixtures::Commit);
    FleetFixtures::node('dev', [RoleName::AppDev]);
    Config::set('app.version', str_repeat('c', 40));

    expect(safetyRun()['status'])->toBe('release_unverified')
        ->and($units->startedLater)->toBe(0)
        ->and($visitor->visited)->toBe([]);
});

describe('fleet rollout foreign CLI', function (): void {
    it('leaves a Node with a foreign CLI out without halting, and takes it back once the file is gone', function (): void {
        ['visitor' => $visitor, 'alerts' => $alerts] = FleetFixtures::bind();
        $beast = FleetFixtures::node('beast', [RoleName::AppDev]);
        FleetFixtures::node('prod', [RoleName::AppProd]);
        $visitor->outcomes['beast'] = FleetNodeOutcome::Skipped;

        expect(safetyRun()['status'])->toBe('completed')
            ->and($visitor->visited)->toBe(['beast', 'prod'])
            ->and($alerts->alerts)->toBe([])
            ->and(app(FleetRolloutMembership::class)->exclusion($beast->fresh()->load('roles')))->toBe('foreign_cli');

        unset($visitor->outcomes['beast']);
        $visitor->visited = [];
        safetyRun();
        expect($visitor->visited)->toBe([]);

        $visitor->cliFixed['beast'] = true;
        safetyRun();

        expect($visitor->visited)->toBe(['beast'])
            ->and(new NodeCliState()->isForeign($beast))->toBeFalse()
            ->and(FleetRolloutNode::query()->where('node_name', 'beast')->sole()->outcome)->toBe(FleetNodeOutcome::Converged);
    });
});

describe('fleet rollout waiting', function (): void {
    it('changes nothing on any Node while it waits for the CLI release, and alerts once after 2 hours', function (): void {
        $artifact = new FakeFootprintArtifact('caddy', 'v1');
        ['visitor' => $visitor, 'alerts' => $alerts] = FleetFixtures::bind([$artifact], published: false);
        FleetFixtures::node('dev', [RoleName::AppDev]);

        expect(safetyRun()['status'])->toBe('waiting')
            ->and($visitor->visited)->toBe([])
            ->and($artifact->applied)->toBe([]);

        $artifact->digest = 'v2';
        safetyRun();
        expect($artifact->applied)->toBe([])
            ->and($alerts->alerts)->toBe([]);

        Carbon::setTestNow(now()->addHours(3));
        safetyRun();
        safetyRun();

        expect($alerts->alerts)->toHaveCount(1)
            ->and($alerts->alerts[0]->kind)->toBe(ReleaseAlertKind::RolloutStalled)
            ->and(FleetRollout::query()->sole()->alert['kind'])->toBe('rollout_stalled')
            ->and(FleetRollout::query()->sole()->status)->toBe(FleetRolloutStatus::Waiting)
            ->and($artifact->applied)->toBe([]);
    });

    it('rolls out an ancestor\'s CLI release once the state falls back, alerts once, and never stalls', function (): void {
        ['visitor' => $visitor, 'catalog' => $catalog, 'alerts' => $alerts] = FleetFixtures::bind();
        $ancestor = str_repeat('e', 40);
        $catalog->missing = [FleetFixtures::Commit];
        app()->instance(ReleaseHistory::class, new FakeReleaseHistory(ancestors: [$ancestor], counts: [$ancestor => 4680]));
        FleetFixtures::node('dev', [RoleName::AppDev]);

        expect(safetyRun()['status'])->toBe('waiting')
            ->and($visitor->visited)->toBe([]);

        Carbon::setTestNow(now()->addSeconds(DesiredFleetState::FallbackAfterSeconds + 1));

        expect(safetyRun()['status'])->toBe('completed')
            ->and($visitor->visited)->toBe(['dev'])
            ->and(FleetRolloutNode::query()->sole()->cli_version)->toBe('0.4680.0')
            ->and(FleetRollout::query()->sole()->desired_state['cli']['commit'])->toBe($ancestor)
            ->and($alerts->alerts)->toHaveCount(1)
            ->and($alerts->alerts[0]->kind)->toBe(ReleaseAlertKind::RolloutCliFallback)
            ->and(FleetRollout::query()->sole()->notices['cli_fallback']['kind'])->toBe('rollout_cli_fallback');

        Carbon::setTestNow(now()->addHours(3));
        safetyRun();

        expect($alerts->alerts)->toHaveCount(1)
            ->and(FleetRollout::query()->sole()->alert)->toBeNull();

        // The commit's own release appears: the catch-up brings the Node to it.
        $catalog->missing = [];
        $visitor->visited = [];
        Carbon::setTestNow(now()->addSeconds(DesiredFleetState::FallbackSeconds + 1));
        safetyRun();

        expect($visitor->visited)->toBe(['dev'])
            ->and(FleetRolloutNode::query()->sole()->cli_version)->toBe('0.4681.0')
            ->and($alerts->alerts)->toHaveCount(1);
    });

    it('visits no Node of a started rollout while its CLI release cannot be confirmed', function (): void {
        ['visitor' => $visitor, 'catalog' => $catalog, 'alerts' => $alerts] = FleetFixtures::bind();
        FleetFixtures::node('dev', [RoleName::AppDev]);
        expect(safetyRun()['status'])->toBe('completed');

        // The confirmed release expires from the cache, and GitHub then answers without it.
        FleetFixtures::node('new', [RoleName::AppDev]);
        $catalog->available = false;
        $visitor->visited = [];
        Carbon::setTestNow(now()->addSeconds(DesiredFleetState::AvailableSeconds + 1));

        expect(safetyRun()['status'])->toBe('waiting')
            ->and($visitor->visited)->toBe([])
            ->and($alerts->alerts)->toBe([])
            ->and(FleetRollout::query()->sole()->status)->toBe(FleetRolloutStatus::Completed);
    });

    it('keeps the incomplete count across a deferred visit', function (): void {
        ['visitor' => $visitor] = FleetFixtures::bind();
        FleetFixtures::node('dev', [RoleName::AppDev]);
        $visitor->outcomes['dev'] = FleetNodeOutcome::Waiting;
        $visitor->waitingCode = 'fleet.self_update_incomplete';
        safetyRun();
        safetyRun();
        $visitor->outcomes['dev'] = FleetNodeOutcome::Deferred;
        safetyRun();
        $visitor->outcomes['dev'] = FleetNodeOutcome::Waiting;
        safetyRun();

        expect(FleetRolloutNode::query()->sole()->evidence['incomplete_visits'])->toBe(3);
    });
});

describe('fleet rollout Caddy skips', function (): void {
    it('tells only the first Node it visits that it is first, and alerts once per rollout for a kept Caddyfile', function (): void {
        ['visitor' => $visitor, 'alerts' => $alerts] = FleetFixtures::bind();
        FleetFixtures::node('dev', [RoleName::AppDev]);
        FleetFixtures::node('db', [RoleName::Database]);
        FleetFixtures::node('prod', [RoleName::AppProd]);
        $skipped = ['footprint' => ['skipped' => ['caddy' => ['reason' => 'caddy_render_refused', 'message' => 'A site is invalid.']]]];
        $visitor->evidence = ['db' => $skipped, 'prod' => $skipped];

        expect(safetyRun()['status'])->toBe('completed')
            ->and($visitor->firstVisits)->toBe([true, false, false])
            ->and($alerts->alerts)->toHaveCount(1)
            ->and($alerts->alerts[0]->kind)->toBe(ReleaseAlertKind::RolloutCaddySkipped)
            ->and(FleetRollout::query()->sole()->notices['caddy_skipped']['kind'])->toBe('rollout_caddy_skipped');
    });
});
