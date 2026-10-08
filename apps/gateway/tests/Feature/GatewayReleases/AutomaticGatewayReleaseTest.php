<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApiException;
use App\Domain\Releases\ReleaseAlertKind;
use App\Infrastructure\GatewayReleases\GatewayReleaseAlerts;
use App\Models\Activity;
use App\Models\GatewayRelease;
use Tests\Support\GatewayReleasePipeline;

pest()->group('subprocess');

beforeEach(function (): void {
    $this->pipeline = new GatewayReleasePipeline;
});

afterEach(function (): void {
    $this->pipeline->cleanup();
});

describe('gateway:release:auto', function (): void {
    it('does nothing while automatic releases are disabled', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->green = $this->pipeline->fixture->commit('Green');

        $tick = $this->pipeline->automatic()->execute();

        expect($tick['result'])->toBe('disabled')
            ->and($this->pipeline->resolutions)->toBe([])
            ->and(GatewayRelease::query()->count())->toBe(0)
            ->and($this->pipeline->automation()->lastTick()['result'] ?? null)->toBe('disabled');
    });

    it('does nothing while a pause marker or a durable pause stands, until resume', function (): void {
        $deployed = $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        file_put_contents($this->pipeline->home.'/gateway-release.paused', '{"release":"abc"}');

        expect($this->pipeline->automatic()->execute()['result'])->toBe('paused')
            ->and($this->pipeline->automation()->pause()['reason'] ?? null)->toBe('marker')
            ->and($this->pipeline->resolutions)->toBe([]);

        unlink($this->pipeline->home.'/gateway-release.paused');
        $record = GatewayRelease::query()->create([
            'release_id' => substr($deployed, 0, 12),
            'sha' => $deployed,
            'trigger' => 'auto',
            'outcome' => 'paused',
            'migrations_ran' => true,
            'phases' => [],
            'duration_ms' => 1,
        ]);
        $this->pipeline->automation()->pauseFor('migration_failure', $record);
        // A newer finished record does not end a durable pause.
        GatewayRelease::query()->create(['release_id' => 'aaaaaaaaaaaa', 'sha' => str_repeat('a', 40), 'trigger' => 'deploy', 'outcome' => 'failed', 'retryable' => true, 'phases' => [], 'duration_ms' => 1]);

        expect($this->pipeline->automatic()->execute()['result'])->toBe('paused')
            ->and($this->pipeline->automation()->resume())->toMatchArray(['reason' => 'migration_failure', 'record' => $record->id])
            ->and($this->pipeline->automatic()->execute()['result'])->toBe('up_to_date')
            ->and($this->pipeline->resolutions)->toHaveCount(1);
    });

    it('exits quietly while another release step holds the lock', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $this->pipeline->green = $this->pipeline->fixture->commit('Green');

        $tick = $this->pipeline->lock()->run(fn (): array => $this->pipeline->automatic()->execute());

        expect($tick['result'])->toBe('busy')
            ->and($this->pipeline->resolutions)->toBe([])
            ->and(GatewayRelease::query()->count())->toBe(0);
    });

    it('reports up to date and passes the deployed commit and failed commits to the resolver', function (): void {
        $deployed = $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $failed = str_repeat('a', 40);
        $retryable = str_repeat('b', 40);
        $rolledBack = str_repeat('c', 40);
        foreach ([[$failed, 'auto', false], [$retryable, 'auto', true], [$rolledBack, 'rollback', false]] as [$sha, $trigger, $isRetryable]) {
            GatewayRelease::query()->create([
                'release_id' => substr($sha, 0, 12),
                'sha' => $sha,
                'trigger' => $trigger,
                'outcome' => 'switched_back',
                'retryable' => $isRetryable,
                'phases' => [],
                'duration_ms' => 1,
            ]);
        }

        $tick = $this->pipeline->automatic()->execute();

        expect($tick['result'])->toBe('up_to_date')
            ->and($tick['sha'])->toBe($deployed)
            ->and($this->pipeline->resolutions)->toBe([[
                'deployed' => $deployed,
                'failed' => [$failed],
                'branch' => 'main',
                'check' => 'Required checks',
                'repository' => 'nckrtl/orbit',
            ]])
            ->and(GatewayRelease::query()->count())->toBe(3);
    });

    it('deploys the releasable commit with the trigger auto', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $sha = $this->pipeline->green = $this->pipeline->fixture->commit('Green');

        $tick = $this->pipeline->automatic()->execute();
        $record = GatewayRelease::query()->sole();

        expect($tick['result'])->toBe('released')
            ->and($tick['record'])->toBe($record->id)
            ->and($record->trigger)->toBe('auto')
            ->and($record->outcome)->toBe('verified')
            ->and($record->sha)->toBe($sha)
            ->and($record->requested)->toBe($sha)
            ->and(array_keys($record->phases))->toBe(['prepare', 'guard', 'configuration', 'snapshot', 'migrate', 'switch', 'handoff', 'verify', 'scheduler', 'web', 'smoke', 'tick'])
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($sha, 0, 12))
            ->and(Activity::query()->where('command', 'gateway:release:auto')->value('status'))->toBe('succeeded')
            ->and($this->pipeline->alerts)->toBe([]);
    });

    it('retries a release that switched back within the retry budget, then alerts once and never ships that commit again', function (): void {
        $deployed = $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $sha = $this->pipeline->green = $this->pipeline->fixture->commit('Broken');
        $this->pipeline->failVerify = $sha;

        $first = $this->pipeline->automatic()->execute();

        expect($first['result'])->toBe('failed')
            ->and(GatewayRelease::query()->sole())
            ->outcome->toBe('switched_back')
            ->retryable->toBeTrue()
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($deployed, 0, 12))
            ->and($this->pipeline->alerts)->toBe([])
            ->and($this->pipeline->automatic()->execute()['result'])->toBe('backing_off');

        $this->travel(11)->minutes();
        $this->pipeline->automatic()->execute();
        $this->travel(11)->minutes();
        $this->pipeline->automatic()->execute();
        $final = GatewayRelease::query()->latest('id')->first();
        new GatewayReleaseAlerts($this->pipeline, $this->pipeline->source())->raise($final->refresh());

        expect(GatewayRelease::query()->count())->toBe(3)
            ->and($final->retryable)->toBeFalse()
            ->and($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->kind)->toBe(ReleaseAlertKind::ReleaseFailed)
            ->and($this->pipeline->alerts[0]->subject->toArray())->toBe([
                'target' => 'gateway',
                'repository' => 'nckrtl/orbit',
                'sha' => $sha,
                'release_id' => (string) $final->id,
            ])
            ->and($final->alert['kind'] ?? null)->toBe('release_failed');

        $this->pipeline->green = null;
        $this->pipeline->automatic()->execute();

        expect(end($this->pipeline->resolutions)['failed'])->toBe([$sha])
            ->and($this->pipeline->alerts)->toHaveCount(1);
    });

    it('pauses and alerts once when a release fails after its migrations ran', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $sha = $this->pipeline->green = $this->pipeline->fixture->commit('Migrates then fails');
        $this->pipeline->failVerify = $sha;
        $this->pipeline->pending = ['2026_10_14_000000_example'];

        $tick = $this->pipeline->automatic()->execute();

        expect($tick['result'])->toBe('paused')
            ->and(GatewayRelease::query()->sole()->outcome)->toBe('paused')
            ->and(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue()
            ->and($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->kind)->toBe(ReleaseAlertKind::ReleasePaused)
            ->and($this->pipeline->automatic()->execute()['result'])->toBe('paused')
            ->and($this->pipeline->resolutions)->toHaveCount(1)
            ->and($this->pipeline->alerts)->toHaveCount(1);
    });

    it('records a GitHub error as a quiet skip and alerts once when it lasts thirty minutes', function (): void {
        $deployed = $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $this->pipeline->githubFailure = new GitHubApiException('GitHub could not be reached.');

        $tick = $this->pipeline->automatic()->execute();

        expect($tick['result'])->toBe('source_unavailable')
            ->and($tick['error_code'])->toBe('gateway.release_source_unavailable')
            ->and($this->pipeline->alerts)->toBe([])
            ->and($this->pipeline->automation()->stall())->not->toBeNull();

        $this->travel(29)->minutes();
        $this->pipeline->automatic()->execute();
        expect($this->pipeline->alerts)->toBe([]);

        $this->travel(2)->minutes();
        $this->pipeline->automatic()->execute();
        $this->pipeline->automatic()->execute();

        expect($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->kind)->toBe(ReleaseAlertKind::ReleaseStalled)
            ->and($this->pipeline->alerts[0]->subject->sha)->toBe($deployed)
            ->and($this->pipeline->alerts[0]->subject->releaseId)->toBeNull();

        $this->pipeline->githubFailure = null;

        expect($this->pipeline->automatic()->execute()['result'])->toBe('up_to_date')
            ->and($this->pipeline->automation()->stall())->toBeNull()
            ->and(GatewayRelease::query()->count())->toBe(0);
    });

    it('alerts once when the branch head stays unreleased for six hours, and reads the head at most every fifteen minutes', function (): void {
        $deployed = $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $this->pipeline->head = str_repeat('d', 40);

        $this->pipeline->automatic()->execute();
        $this->pipeline->automatic()->execute();

        expect($this->pipeline->headReads)->toBe(1)
            ->and($this->pipeline->automation()->branchHead()['behind_since'] ?? null)->not->toBeNull();

        $this->travel(5)->hours();
        $this->pipeline->automatic()->execute();
        expect($this->pipeline->alerts)->toBe([])
            ->and($this->pipeline->headReads)->toBe(2);

        $this->travel(61)->minutes();
        $this->pipeline->head = str_repeat('e', 40);
        $this->pipeline->automatic()->execute();
        $this->travel(16)->minutes();
        $this->pipeline->automatic()->execute();

        expect($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->kind)->toBe(ReleaseAlertKind::ReleaseStalled)
            ->and($this->pipeline->alerts[0]->subject->sha)->toBe($deployed)
            ->and($this->pipeline->alerts[0]->summary)->toContain('eeeeeeeeeeee');

        $this->pipeline->head = null;
        $this->travel(16)->minutes();
        $this->pipeline->automatic()->execute();

        expect($this->pipeline->automation()->branchHead())
            ->behind_since->toBeNull()
            ->alerted->toBeFalse()
            ->and($this->pipeline->alerts)->toHaveCount(1);
    });

    it('does not release before the Gateway runs from a release with a readable revision', function (): void {
        $this->pipeline->automation()->enable();

        expect($this->pipeline->automatic()->execute()['result'])->toBe('not_adopted');

        $this->pipeline->adopt();
        $current = $this->pipeline->fixture->layout->currentReleaseId();
        $revision = $this->pipeline->fixture->layout->releasePath((string) $current).'/REVISION';
        chmod(dirname($revision), 0755);
        unlink($revision);

        expect($this->pipeline->automatic()->execute()['result'])->toBe('no_deployed_release')
            ->and($this->pipeline->resolutions)->toBe([])
            ->and($this->pipeline->alerts)->toBe([]);
    });

    it('backs off a commit whose release failed for a transient reason', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->automation()->enable();
        $sha = $this->pipeline->green = $this->pipeline->fixture->commit('Snapshot fails');
        GatewayRelease::query()->create([
            'release_id' => substr($sha, 0, 12),
            'sha' => $sha,
            'requested' => $sha,
            'trigger' => 'auto',
            'outcome' => 'failed',
            'retryable' => true,
            'error_code' => 'gateway.release_snapshot_failed',
            'phases' => [],
            'duration_ms' => 1,
        ]);

        expect($this->pipeline->automatic()->execute()['result'])->toBe('backing_off');

        $this->travel(11)->minutes();

        expect($this->pipeline->automatic()->execute()['result'])->toBe('released');
    });

    it('prints the tick as JSON from the timer command', function (): void {
        $this->pipeline->adopt();
        $this->pipeline->bind();

        $this->artisan('gateway:release:auto')
            ->expectsOutputToContain('"result":"disabled"')
            ->assertExitCode(0);
    });
});
