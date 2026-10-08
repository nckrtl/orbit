<?php

declare(strict_types=1);

use App\Domain\Problems\ProblemSource;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertOutcome;
use App\Domain\Releases\ReleaseAlertSubject;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\Activity;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

const RELEASE_ALERT_URL = 'https://hooks.example.test/services/T000/B000/release-alert-token';

const RELEASE_ALERT_SECRET = 'release-alert-secret';

const RELEASE_ALERT_SHA = '0123456789abcdef0123456789abcdef01234567';

beforeEach(function (): void {
    Http::preventStrayRequests();
    app(TaskExtensionState::class)->enable();
    config()->set('orbit.releases.alert_webhook_url', RELEASE_ALERT_URL);
    config()->set('orbit.releases.alert_webhook_secret', RELEASE_ALERT_SECRET);
});

function release_alert(
    ReleaseAlertKind $kind = ReleaseAlertKind::ReleaseFailed,
    string $summary = 'Smoke check web.index failed.',
    ?string $evidenceUrl = 'https://github.com/nckrtl/orbit/actions/runs/42',
): ReleaseAlert {
    return new ReleaseAlert(
        $kind,
        new ReleaseAlertSubject('gateway', 'nckrtl/orbit', RELEASE_ALERT_SHA, '17'),
        $summary,
        $evidenceUrl,
    );
}

function release_alert_activity(): Activity
{
    return Activity::query()->where('command', 'release:alert')->sole();
}

describe('a raised alert', function (): void {
    it('records Activity and a problem, then posts the signed body', function (): void {
        $this->freezeTime();
        Http::fake([RELEASE_ALERT_URL => Http::response(['ok' => true])]);

        $receipt = app(ReleaseAlertNotifier::class)->alert(release_alert());

        $fingerprint = 'release|release_failed|gateway|'.RELEASE_ALERT_SHA;
        $activity = release_alert_activity();
        $subject = ['target' => 'gateway', 'repository' => 'nckrtl/orbit', 'sha' => RELEASE_ALERT_SHA, 'release_id' => '17'];

        Http::assertSent(function (Request $request) use ($activity, $fingerprint, $subject): bool {
            $timestamp = (string) now()->timestamp;
            $body = $request->body();

            return $request->url() === RELEASE_ALERT_URL
                && $request->hasHeader('X-Orbit-Timestamp', $timestamp)
                && $request->hasHeader('X-Orbit-Signature', 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, RELEASE_ALERT_SECRET))
                && $request->isJson()
                && $request->data() === [
                    'event' => 'release.alert',
                    'kind' => 'release_failed',
                    'subject' => $subject,
                    'summary' => 'Smoke check web.index failed.',
                    'evidence_url' => 'https://github.com/nckrtl/orbit/actions/runs/42',
                    'text' => 'Release failed for gateway nckrtl/orbit@0123456789ab: Smoke check web.index failed. https://github.com/nckrtl/orbit/actions/runs/42',
                    'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'request_id' => $activity->request_id,
                    'activity_id' => $activity->id,
                    'problem_fingerprint' => $fingerprint,
                ]
                && ! str_contains($body, RELEASE_ALERT_SECRET);
        });

        $problem = ProblemFingerprint::query()->sole();

        expect($receipt->toArray())->toBe([
            'request_id' => $activity->request_id,
            'activity_id' => $activity->id,
            'problem' => ['outcome' => 'done', 'fingerprint' => $fingerprint],
            'webhook' => ['outcome' => 'done'],
        ])
            ->and($activity->log_name)->toBe('releases')
            ->and($activity->description)->toBe('release_failed')
            ->and($activity->status)->toBe('succeeded')
            ->and($activity->error_code)->toBeNull()
            ->and($activity->duration_ms)->toBeInt()
            ->and($activity->properties?->toArray())->toBe([
                'kind' => 'release_failed',
                'subject' => $subject,
                'summary' => 'Smoke check web.index failed.',
                'evidence_url' => 'https://github.com/nckrtl/orbit/actions/runs/42',
                'problem' => ['outcome' => 'done', 'fingerprint' => $fingerprint],
                'webhook' => ['outcome' => 'done'],
            ])
            ->and($problem->fingerprint)->toBe($fingerprint)
            ->and($problem->source)->toBe(ProblemSource::Release)
            ->and($problem->occurrences)->toBe(1)
            ->and($problem->evidence['summary'] ?? null)->toBe('Smoke check web.index failed.')
            ->and($problem->evidence['release_repository'] ?? null)->toBe('nckrtl/orbit')
            ->and($problem->evidence['release_id'] ?? null)->toBe('17')
            ->and($problem->evidence['evidence_urls'] ?? null)->toBe(['https://github.com/nckrtl/orbit/actions/runs/42'])
            ->and($problem->evidence['activity_ids'] ?? null)->toBe([$activity->id]);
    });

    it('is filed by the next problems:file run on its first occurrence', function (): void {
        Http::fake([RELEASE_ALERT_URL => Http::response(['ok' => true])]);
        Project::query()->create([
            'name' => 'Orbit',
            'slug' => 'orbit',
            'repository_url' => 'https://example.test/orbit.git',
            'default_branch' => 'main',
        ]);

        app(ReleaseAlertNotifier::class)->alert(release_alert(ReleaseAlertKind::ReleasePaused, 'Health check failed after migrations ran.'));

        expect(Artisan::call('problems:file'))->toBe(0);

        $task = Task::topLevel()->sole();
        $activityId = release_alert_activity()->id;

        expect($task->title)->toBe('Release paused for gateway at 0123456789ab')
            ->and($task->brief)->toContain("Symptom\nHealth check failed after migrations ran.")
            ->and($task->brief)->toContain("Count\n1")
            ->and($task->brief)->toContain("Activity ids: {$activityId}")
            ->and($task->brief)->toContain('Evidence links: https://github.com/nckrtl/orbit/actions/runs/42')
            ->and($task->brief)->toContain("Suspected entry point\nnckrtl/orbit@".RELEASE_ALERT_SHA.', release 17')
            ->and(ProblemFingerprint::query()->sole()->task_group_id)->toBe($task->id);
    });

    it('redacts credentials in the summary and cuts it at 1000 characters', function (): void {
        Http::fake([RELEASE_ALERT_URL => Http::response(['ok' => true])]);

        app(ReleaseAlertNotifier::class)->alert(release_alert(summary: 'Smoke failed with token=s3cr3tTokenValue99 '.str_repeat('x', 1200)));

        $summary = release_alert_activity()->properties?->get('summary');

        expect($summary)->toBeString()
            ->and($summary)->not->toContain('s3cr3tTokenValue99')
            ->and($summary)->toStartWith('Smoke failed with token=[REDACTED] ')
            ->and(mb_strlen((string) $summary))->toBe(1003)
            ->and($summary)->toEndWith('...');
        Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 's3cr3tTokenValue99'));
    });

    it('rejects a subject outside the bounds before it records anything', function (Closure $build): void {
        Http::fake();

        expect($build)->toThrow(InvalidArgumentException::class)
            ->and(Activity::query()->count())->toBe(0)
            ->and(ProblemFingerprint::query()->count())->toBe(0);
        Http::assertNothingSent();
    })->with([
        'short sha' => [fn () => new ReleaseAlertSubject('gateway', 'nckrtl/orbit', '0123456')],
        'uppercase sha' => [fn () => new ReleaseAlertSubject('gateway', 'nckrtl/orbit', strtoupper(RELEASE_ALERT_SHA))],
        'pipe in target' => [fn () => new ReleaseAlertSubject('gate|way', 'nckrtl/orbit', RELEASE_ALERT_SHA)],
        'repository without owner' => [fn () => new ReleaseAlertSubject('gateway', 'orbit', RELEASE_ALERT_SHA)],
        'release id with a space' => [fn () => new ReleaseAlertSubject('gateway', 'nckrtl/orbit', RELEASE_ALERT_SHA, 'release 1')],
        'empty summary' => [fn () => release_alert(summary: '  ')],
        'non-http evidence link' => [fn () => release_alert(evidenceUrl: 'file:///etc/passwd')],
    ]);
});

describe('the webhook part', function (): void {
    it('is skipped when the URL or the secret is unset, and the rest is still recorded', function (string $key): void {
        Http::fake();
        config()->set($key, '');

        $receipt = app(ReleaseAlertNotifier::class)->alert(release_alert());

        Http::assertNothingSent();
        expect($receipt->webhook->toArray())->toBe(['outcome' => 'skipped', 'reason' => 'not_configured'])
            ->and($receipt->problem->outcome)->toBe(ReleaseAlertOutcome::Done)
            ->and(release_alert_activity()->status)->toBe('succeeded')
            ->and(ProblemFingerprint::query()->count())->toBe(1);
    })->with(['orbit.releases.alert_webhook_url', 'orbit.releases.alert_webhook_secret']);

    it('records a refused post without failing the caller or storing the URL', function (int $status): void {
        Http::fake([RELEASE_ALERT_URL => Http::response('internal detail', $status, ['Location' => 'https://elsewhere.example.test/'])]);

        $receipt = app(ReleaseAlertNotifier::class)->alert(release_alert());
        $activity = release_alert_activity();
        $stored = json_encode($activity->properties, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        Http::assertSentCount(1);
        expect($receipt->webhook->toArray())->toBe(['outcome' => 'failed', 'reason' => 'rejected', 'http_status' => $status])
            ->and($receipt->problem->outcome)->toBe(ReleaseAlertOutcome::Done)
            ->and($activity->status)->toBe('failed')
            ->and($activity->error_code)->toBe('release.alert_webhook_failed')
            ->and($activity->properties?->get('webhook'))->toBe(['outcome' => 'failed', 'reason' => 'rejected', 'http_status' => $status])
            ->and($stored)->not->toContain('release-alert-token')
            ->and($stored)->not->toContain(RELEASE_ALERT_SECRET)
            ->and($stored)->not->toContain('internal detail');
    })->with([500, 403, 302]);

    it('records an unreachable receiver without failing the caller', function (): void {
        Http::fake([RELEASE_ALERT_URL => Http::failedConnection()]);

        $receipt = app(ReleaseAlertNotifier::class)->alert(release_alert());

        expect($receipt->webhook->toArray())->toBe(['outcome' => 'failed', 'reason' => 'unreachable'])
            ->and(release_alert_activity()->error_code)->toBe('release.alert_webhook_failed');
    });
});

describe('the problem part', function (): void {
    it('is skipped while the Tasks extension is disabled', function (): void {
        Http::fake([RELEASE_ALERT_URL => Http::response(['ok' => true])]);
        app(TaskExtensionState::class)->disable();

        $receipt = app(ReleaseAlertNotifier::class)->alert(release_alert());

        expect($receipt->problem->toArray())->toBe(['outcome' => 'skipped', 'reason' => 'tasks_disabled'])
            ->and(ProblemFingerprint::query()->count())->toBe(0)
            ->and(release_alert_activity()->status)->toBe('succeeded');
        Http::assertSent(fn (Request $request): bool => $request->data()['problem_fingerprint'] === null);
    });

    it('is skipped for a suppressed fingerprint', function (): void {
        Http::fake([RELEASE_ALERT_URL => Http::response(['ok' => true])]);
        $fingerprint = 'release|release_failed|gateway|'.RELEASE_ALERT_SHA;
        config()->set('orbit.problems.suppressed_fingerprints', [$fingerprint]);

        $receipt = app(ReleaseAlertNotifier::class)->alert(release_alert());

        expect($receipt->problem->toArray())->toBe(['outcome' => 'skipped', 'reason' => 'suppressed', 'fingerprint' => $fingerprint])
            ->and(ProblemFingerprint::query()->count())->toBe(0);
    });

    it('fails on its own, and the webhook is still posted', function (): void {
        Exceptions::fake();
        Http::fake([RELEASE_ALERT_URL => Http::response(['ok' => true])]);
        Schema::drop('problem_fingerprints');

        $receipt = app(ReleaseAlertNotifier::class)->alert(release_alert());
        $activity = release_alert_activity();

        Http::assertSentCount(1);
        Exceptions::assertReportedCount(1);
        expect($receipt->problem->toArray())->toBe(['outcome' => 'failed', 'reason' => 'error'])
            ->and($receipt->webhook->outcome)->toBe(ReleaseAlertOutcome::Done)
            ->and($activity->status)->toBe('failed')
            ->and($activity->error_code)->toBe('release.alert_problem_failed');
    });
});
