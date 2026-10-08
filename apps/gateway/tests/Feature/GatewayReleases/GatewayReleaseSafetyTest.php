<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\SettleGatewayReleaseAction;
use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\Releases\ReleaseAlertKind;
use App\Infrastructure\GatewayReleases\GatewayReleaseAlerts;
use App\Models\GatewayRelease;
use Tests\Support\GatewayReleasePipeline;

beforeEach(function (): void {
    $this->pipeline = new GatewayReleasePipeline;
});

afterEach(function (): void {
    $this->pipeline->cleanup();
});

/** @param array<string, mixed> $phases */
function dead_release(string $outcome, array $phases, ?string $sha = null): GatewayRelease
{
    return GatewayRelease::query()->create([
        'release_id' => $sha === null ? null : substr($sha, 0, 12),
        'sha' => $sha,
        'requested' => $sha ?? 'abcdef1',
        'trigger' => 'auto',
        'outcome' => $outcome,
        'phases' => $phases,
        'duration_ms' => 0,
    ]);
}

describe('manual releases and automatic releases', function (): void {
    it('pauses after a verified rollback with one alert, so the next tick does not undo it', function (): void {
        $first = $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $second = $this->pipeline->fixture->commit('Second');
        $this->pipeline->deployer()->execute($second);
        $this->pipeline->green = $second;

        $this->pipeline->rollback()->execute(substr($first, 0, 12));
        $tick = $this->pipeline->automatic()->execute();
        $this->pipeline->automatic()->execute();

        expect($tick['result'])->toBe('paused')
            ->and($this->pipeline->automation()->pause())->toMatchArray(['reason' => 'rollback', 'sha' => $first])
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($first, 0, 12))
            ->and($this->pipeline->resolutions)->toHaveCount(1)
            ->and($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->kind)->toBe(ReleaseAlertKind::ReleasePaused)
            ->and($this->pipeline->alerts[0]->summary)->toContain('(rollback)');
    });

    it('pauses with one alert instead of undoing a manual deploy that pinned an older commit, until resume', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $older = $this->pipeline->fixture->commit('Older');
        $newest = $this->pipeline->fixture->commit('Newest');
        $this->pipeline->green = $newest;
        $this->pipeline->deployer()->execute($older);

        $tick = $this->pipeline->automatic()->execute();

        expect(GatewayRelease::query()->latest('id')->first()->phases['newest_green'])->toBe(['outcome' => 'superseded', 'sha' => $newest])
            ->and($tick['result'])->toBe('paused')
            ->and($tick['error_code'])->toBe('gateway.release_manual_deploy')
            ->and($this->pipeline->automation()->pause()['reason'] ?? null)->toBe('manual_deploy')
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($older, 0, 12))
            ->and($this->pipeline->automatic()->execute()['result'])->toBe('paused')
            ->and($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->kind)->toBe(ReleaseAlertKind::ReleasePaused);

        $this->pipeline->automation()->resume();

        expect($this->pipeline->automatic()->execute()['result'])->toBe('released')
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($newest, 0, 12));
    });

    it('hands back to automation after a manual deploy of the newest green commit, also when a newer one turns green later', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $newest = $this->pipeline->fixture->commit('Newest');
        $this->pipeline->deployer()->execute($newest);

        expect(GatewayRelease::query()->latest('id')->first()->phases['newest_green'])->toBe(['outcome' => 'newest'])
            ->and($this->pipeline->automatic()->execute()['result'])->toBe('up_to_date')
            ->and($this->pipeline->automation()->pause())->toBeNull();

        $later = $this->pipeline->green = $this->pipeline->fixture->commit('Later');

        expect($this->pipeline->automatic()->execute()['result'])->toBe('released')
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($later, 0, 12))
            ->and($this->pipeline->alerts)->toBe([]);
    });

    it('resumes automation after a forward fix that goes live during a migration failure pause', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $broken = $this->pipeline->green = $this->pipeline->fixture->commit('Migrates then fails');
        $this->pipeline->failVerify = $broken;
        $this->pipeline->pending = ['2026_10_14_000000_example'];
        $this->pipeline->automatic()->execute();
        expect($this->pipeline->automation()->pause()['reason'] ?? null)->toBe('migration_failure');

        $this->pipeline->failVerify = null;
        $this->pipeline->pending = [];
        $this->pipeline->green = null;
        $fix = $this->pipeline->fixture->commit('Forward fix');
        $this->pipeline->deployer()->execute($fix, force: true);
        $next = $this->pipeline->green = $this->pipeline->fixture->commit('Next');

        expect($this->pipeline->automation()->pause())->toBeNull()
            ->and($this->pipeline->automatic()->execute()['result'])->toBe('released')
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($next, 0, 12));
    });

    it('alerts once when the branch head stays unreleased for six hours while paused', function (): void {
        $deployed = $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $this->pipeline->automation()->pauseFor('marker');
        $this->pipeline->head = str_repeat('d', 40);

        $this->pipeline->automatic()->execute();
        $this->travel(6)->hours();
        $this->travel(16)->minutes();
        $this->pipeline->automatic()->execute();
        $this->travel(16)->minutes();
        $this->pipeline->automatic()->execute();

        expect($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->kind)->toBe(ReleaseAlertKind::ReleaseStalled)
            ->and($this->pipeline->alerts[0]->subject->sha)->toBe($deployed)
            ->and($this->pipeline->resolutions)->toBe([]);
    });
});

describe('what ends a pause', function (): void {
    it('keeps a pause when an adoption is resumed, and ends it only with a verified release', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->automation()->pauseFor('migration_failure');
        file_put_contents($this->pipeline->home.'/gateway-release.paused', '{}');
        $sha = str_repeat('a', 40);
        $release = static fn (string $outcome, string $trigger): DeployedGatewayRelease => new DeployedGatewayRelease(
            id: substr($sha, 0, 12), sha: $sha, outcome: $outcome, trigger: $trigger, migrationsRan: false, previousId: null,
            snapshotPath: null, cleanupPaused: false, retryable: false, durationMs: 1, phases: [],
        );

        $this->pipeline->recorder()->write($release('resumed', 'adopt'));

        expect($this->pipeline->automation()->pause()['reason'] ?? null)->toBe('migration_failure')
            ->and(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();

        $this->pipeline->recorder()->write($release('verified', 'adopt'));

        expect($this->pipeline->automation()->pause())->toBeNull()
            ->and(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeFalse();
    });
});

describe('releases that died', function (): void {
    it('ends a running record the next tick finds dead, alerts, and pauses when it had migrated', function (): void {
        $deployed = $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $sha = str_repeat('b', 40);
        $dead = dead_release('running', [
            'prepare' => ['outcome' => 'prepared'],
            'snapshot' => ['outcome' => 'snapshotted', 'path' => '/tmp/pre.sqlite'],
        ], $sha);

        $tick = $this->pipeline->automatic()->execute();

        expect($tick['result'])->toBe('paused')
            ->and($dead->refresh())
            ->outcome->toBe('interrupted')
            ->error_code->toBe('gateway.release_interrupted')
            ->and($this->pipeline->automation()->pause())->toMatchArray(['reason' => 'interrupted', 'record' => $dead->id])
            ->and($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->kind)->toBe(ReleaseAlertKind::ReleasePaused)
            ->and($this->pipeline->resolutions)->toBe([])
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($deployed, 0, 12));
    });

    it('ends a dead record that never touched live state without a pause, and keeps releasing', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $dead = dead_release('running', ['prepare' => ['outcome' => 'prepared']], str_repeat('c', 40));

        expect($this->pipeline->automatic()->execute()['result'])->toBe('up_to_date')
            ->and($dead->refresh()->outcome)->toBe('interrupted')
            ->and($dead->retryable)->toBeTrue()
            ->and($this->pipeline->automation()->pause())->toBeNull()
            ->and($this->pipeline->alerts)->toBe([]);
    });

    it('settles the record of a unit that stopped, from ExecStopPost', function (): void {
        $this->pipeline->bind();
        $dead = dead_release('running', ['switch' => ['outcome' => 'switched']], str_repeat('d', 40));
        $finished = dead_release('verified', [], str_repeat('e', 40));

        $this->artisan('gateway:release:settle', ['record' => (string) $dead->id])
            ->expectsOutputToContain('"settled":['.$dead->id.']')
            ->assertExitCode(0);
        $this->artisan('gateway:release:settle', ['record' => (string) $finished->id])
            ->expectsOutputToContain('"settled":[]')
            ->assertExitCode(0);

        expect($dead->refresh()->outcome)->toBe('interrupted')
            ->and($finished->refresh()->outcome)->toBe('verified')
            ->and($this->pipeline->automation()->pause()['reason'] ?? null)->toBe('interrupted');
    });

    it('spends the retry budget on interrupted attempts, then marks the commit failed and alerts once', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $sha = str_repeat('c', 40);

        foreach ([true, true, false] as $retryable) {
            $dead = dead_release('running', ['prepare' => ['outcome' => 'prepared']], $sha);
            $this->pipeline->automatic()->execute();

            expect($dead->refresh()->retryable)->toBe($retryable);
        }

        expect(GatewayRelease::failedShas())->toBe([$sha])
            ->and($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->kind)->toBe(ReleaseAlertKind::ReleaseFailed)
            ->and(end($this->pipeline->resolutions)['failed'])->toBe([$sha]);
    });

    it('does not end a running record by id while a release holds the lock', function (): void {
        $this->pipeline->bind();
        $running = dead_release('running', ['switch' => ['outcome' => 'switched']], str_repeat('f', 40));

        $settled = $this->pipeline->lock()->run(fn (): array => app(SettleGatewayReleaseAction::class)->execute((string) $running->id));

        expect($settled)->toBe([])
            ->and($running->refresh()->outcome)->toBe('running')
            ->and(app(SettleGatewayReleaseAction::class)->execute((string) $running->id))->toBe([$running->id]);
    });

    it('settles nothing without a record id while a release holds the lock', function (): void {
        $this->pipeline->bind();
        $running = dead_release('running', [], str_repeat('f', 40));

        $settled = $this->pipeline->lock()->run(fn (): array => app(SettleGatewayReleaseAction::class)->execute());

        expect($settled)->toBe([])
            ->and($running->refresh()->outcome)->toBe('running')
            ->and(app(SettleGatewayReleaseAction::class)->execute())->toBe([$running->id]);
    });

    it('ends a queued record whose unit did not run within two minutes', function (): void {
        $this->pipeline->bind();
        $queued = $this->pipeline->recorder()->queue('deploy', 'abcdef1', null);

        expect(app(SettleGatewayReleaseAction::class)->execute())->toBe([]);

        $this->travel(3)->minutes();
        $this->pipeline->unitActive = true;
        expect(app(SettleGatewayReleaseAction::class)->execute())->toBe([]);

        $this->pipeline->unitActive = false;
        expect(app(SettleGatewayReleaseAction::class)->execute())->toBe([$queued->id])
            ->and($queued->refresh()->outcome)->toBe('interrupted');
    });

    it('lets a queued request run before the tick takes the lock', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $this->pipeline->green = $this->pipeline->fixture->commit('Green');
        $this->pipeline->recorder()->queue('deploy', 'abcdef1', null);

        expect($this->pipeline->automatic()->execute())
            ->result->toBe('busy')
            ->error_code->toBe('gateway.release_queued')
            ->and($this->pipeline->resolutions)->toBe([]);
    });
});

describe('switch-back after a retry', function (): void {
    it('pauses instead of switching back when the database has a migration the previous release lacks', function (): void {
        $first = $this->pipeline->adopt();
        $sha = $this->pipeline->fixture->commit('Migrated by a killed attempt');
        // A killed attempt of this commit already migrated, so nothing is pending now.
        $this->pipeline->applied = ['2026_10_20_000000_example'];
        $this->pipeline->files = [substr($sha, 0, 12) => ['2026_10_20_000000_example.php']];
        $this->pipeline->failVerify = $sha;

        release_failure(fn () => $this->pipeline->deployer()->execute($sha));
        $record = GatewayRelease::query()->latest('id')->first();

        expect($record->outcome)->toBe('paused')
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($sha, 0, 12))
            ->and($this->pipeline->fixture->layout->currentReleaseId())->not->toBe(substr($first, 0, 12))
            ->and($this->pipeline->automation()->pause()['reason'] ?? null)->toBe('migration_failure');
    });

    it('pauses on the record instead of switching back when the migrations table cannot be read', function (): void {
        $first = $this->pipeline->adopt();
        $sha = $this->pipeline->fixture->commit('Broken, schema unreadable at switch-back');
        $this->pipeline->failVerify = $sha;
        $deployer = $this->pipeline->deployer();
        $this->pipeline->files = [substr($first, 0, 12) => [], substr($sha, 0, 12) => []];
        // Readable for the guard before the switch; unreadable once verify failed.
        $this->pipeline->onVerify = function (): void {
            $this->pipeline->appliedFails = true;
        };

        release_failure(fn () => $deployer->execute($sha));
        $record = GatewayRelease::query()->latest('id')->first();

        expect($record->outcome)->toBe('paused')
            ->and($record->finished())->toBeTrue()
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($sha, 0, 12))
            ->and($this->pipeline->automation()->pause()['reason'] ?? null)->toBe('migration_failure')
            ->and($this->pipeline->alerts[0]->kind ?? null)->toBe(ReleaseAlertKind::ReleasePaused);
    });

    it('switches back when the previous release knows every applied migration', function (): void {
        $first = $this->pipeline->adopt();
        $sha = $this->pipeline->fixture->commit('Broken without migrations');
        $this->pipeline->applied = ['2026_01_01_000000_base'];
        $this->pipeline->files = [substr($first, 0, 12) => ['2026_01_01_000000_base.php'], substr($sha, 0, 12) => ['2026_01_01_000000_base.php']];
        $this->pipeline->failVerify = $sha;

        release_failure(fn () => $this->pipeline->deployer()->execute($sha));

        expect(GatewayRelease::query()->latest('id')->value('outcome'))->toBe('switched_back')
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($first, 0, 12));
    });
});

describe('release alerts and stalls', function (): void {
    it('alerts once when a verified release leaves document cleanup paused', function (): void {
        $record = dead_release('verified', ['handoff' => ['cleanup_paused' => true, 'cleanup_error_code' => 'project_documents.cleanup_reconcile_differs']], str_repeat('a', 40));
        $record->forceFill(['cleanup_paused' => true])->save();
        $alerts = new GatewayReleaseAlerts($this->pipeline, $this->pipeline->source());

        $alerts->raise($record);
        $alerts->raise($record->refresh());

        expect($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->kind)->toBe(ReleaseAlertKind::ReleaseCleanupPaused)
            ->and($this->pipeline->alerts[0]->summary)->toContain('project_documents.cleanup_reconcile_differs');
    });

    it('keeps a release live when the Gateway Node agent update fails, records why, and alerts once', function (): void {
        $first = $this->pipeline->adopt();
        $this->pipeline->gatewayAgent = ['outcome' => 'failed', 'version' => '0.4.0', 'error_code' => 'agent.unhealthy', 'message' => 'The new orbit-agent did not stay running. The previous orbit-agent is restored.'];
        $second = $this->pipeline->fixture->commit('Second');

        $this->pipeline->deployer()->execute($second);
        $record = GatewayRelease::query()->latest('id')->firstOrFail();
        $alertedByDeploy = $this->pipeline->alerts;
        new GatewayReleaseAlerts($this->pipeline, $this->pipeline->source())->gatewayAgent($record);

        expect($alertedByDeploy)->toHaveCount(1)
            ->and($record->outcome)->toBe('verified')
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($second, 0, 12))
            ->and($this->pipeline->steps)->not->toContain('handoff:'.substr($first, 0, 12))
            ->and($record->phases['scheduler']['gateway_agent'])->toMatchArray(['outcome' => 'failed', 'error_code' => 'agent.unhealthy'])
            ->and($record->phases['scheduler']['gateway_agent']['alert']['kind'])->toBe('release_gateway_agent_failed')
            ->and($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->kind)->toBe(ReleaseAlertKind::ReleaseGatewayAgentFailed)
            ->and($this->pipeline->alerts[0]->subject->sha)->toBe($second)
            ->and($this->pipeline->alerts[0]->summary)->toContain('agent.unhealthy', '0.4.0');
    });

    it('raises no agent alert for a Gateway Node agent that already matched or was updated', function (string $outcome): void {
        $this->pipeline->adopt();
        $this->pipeline->gatewayAgent = ['outcome' => $outcome, 'version' => '0.4.0'];

        $this->pipeline->deployer()->execute($this->pipeline->fixture->commit('Second'));

        expect(GatewayRelease::query()->latest('id')->value('outcome'))->toBe('verified')
            ->and($this->pipeline->alerts)->toBe([]);
    })->with(['unchanged', 'updated']);

    it('counts a lock held for thirty minutes toward the stall alert', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->automation()->enable();

        $this->pipeline->lock()->run(function (): void {
            $this->pipeline->automatic()->execute();
            $this->travel(31)->minutes();
            $this->pipeline->automatic()->execute();
        });

        expect($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->kind)->toBe(ReleaseAlertKind::ReleaseStalled);
    });

    it('clears a stall while automatic releases are disabled or paused', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->automation()->stalled();

        $this->pipeline->automatic()->execute();

        expect($this->pipeline->automation()->stall())->toBeNull();
    });
});
