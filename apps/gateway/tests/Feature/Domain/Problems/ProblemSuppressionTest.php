<?php

declare(strict_types=1);

use App\Domain\Problems\ProblemSource;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Activity;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\TestOrbitHome;

it('ships problem suppression config beside tasks with an empty fingerprint list and the T3 path prefix', function (): void {
    expect(config('orbit.problems'))->toBe([
        'suppressed_fingerprints' => [],
        'suppressed_path_prefixes' => [
            'app/Infrastructure/Tasks/T3/',
        ],
    ])->and(array_key_exists('tasks', config('orbit')))->toBeTrue()
        ->and(array_key_exists('problems', config('orbit')))->toBeTrue();
});

it('suppresses an exact fingerprint and a path prefix and still counts an unsuppressed key', function (): void {
    config()->set('orbit.problems.suppressed_fingerprints', [
        'activity|instance:clone|instance.clone_failed',
    ]);
    problem_suppression_sandbox();
    problem_suppression_append('');
    Artisan::call('problems:collect');

    $seen = Carbon::parse('2026-09-01 00:00:00', 'UTC');
    $existing = ProblemFingerprint::query()->create([
        'fingerprint' => 'activity|instance:clone|instance.clone_failed',
        'source' => ProblemSource::Activity,
        'first_seen' => $seen,
        'last_seen' => $seen,
        'occurrences' => 1,
        'evidence' => [
            'error_message' => 'old',
            'observation_times' => ['2026-09-01T00:00:00.000000Z'],
            'observation_counts' => [1],
            'counted_blocks' => [intdiv($seen->getTimestamp(), 300)],
        ],
    ]);
    problem_suppression_activity_at(
        'instance:clone',
        'instance.clone_failed',
        1,
        Carbon::parse('2026-10-01 00:01:06', 'UTC'),
        'req-clone',
    );
    $hidden = problem_suppression_activity('instance:delete', 'instance.delete_failed', 1, 'req-delete');
    $hidden->forceFill([
        'properties' => [
            'path' => 'app/Infrastructure/Tasks/T3/Driver.php',
            'error_message' => 'hidden',
        ],
    ])->save();
    problem_suppression_activity('instance:clone', 'Instance.clone_failed', 1, 'req-case');
    problem_suppression_activity('route:publish', 'route.publish_failed', 1, 'req-publish');

    $t3 = base_path().'/app/Infrastructure/Tasks/T3/Driver.php';
    $kept = base_path().'/app/Domain/Tasks/TaskScheduler.php';
    problem_suppression_append(problem_suppression_record(
        'RuntimeException',
        $t3,
        'App\\Infrastructure\\Tasks\\T3\\Driver->run()',
        'req-t3',
        'T3 subscription ended.',
        '2026-10-01 00:01:06',
    ));
    problem_suppression_append(problem_suppression_record(
        'RuntimeException',
        $kept,
        'App\\Domain\\Tasks\\TaskScheduler->tick()',
        'req-kept',
        'Scheduler failed.',
        '2026-10-01 00:06:06',
    ));

    expect(Artisan::call('problems:collect'))->toBe(0);

    $stored = ProblemFingerprint::query()->orderBy('fingerprint')->pluck('fingerprint')->all();

    expect($stored)->toBe([
        'activity|instance:clone|Instance.clone_failed',
        'activity|instance:clone|instance.clone_failed',
        'activity|route:publish|route.publish_failed',
        'log|RuntimeException|app/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick',
    ])->and($existing->refresh()->occurrences)->toBe(1)
        ->and($existing->evidence['error_message'])->toBe('old')
        ->and($existing->last_seen?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-01 00:00:00');

    $log = ProblemFingerprint::query()->where('source', ProblemSource::Log)->sole();

    expect($log->occurrences)->toBe(1)
        ->and($log->evidence['source_path'])->toBe('app/Domain/Tasks/TaskScheduler.php')
        ->and($log->evidence['request_ids'])->toBe(['req-kept']);

    expect(Artisan::call('problems:collect'))->toBe(0)
        ->and(ProblemFingerprint::query()->count())->toBe(4)
        ->and($log->refresh()->occurrences)->toBe(1)
        ->and($existing->refresh()->occurrences)->toBe(1);

    problem_suppression_append(problem_suppression_record(
        'LogicException',
        $kept,
        'App\\Domain\\Tasks\\TaskScheduler->tick()',
        'req-next',
        'Scheduler failed again.',
        '2026-10-01 00:11:06',
    ));

    expect(Artisan::call('problems:collect'))->toBe(0)
        ->and(ProblemFingerprint::query()->where(
            'fingerprint',
            'log|LogicException|app/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick',
        )->exists())->toBeTrue();
});

it('records a log source path for an open window and does not replace it while suppression is on', function (): void {
    problem_suppression_sandbox();
    problem_suppression_append('');
    Artisan::call('problems:collect');
    $seen = Carbon::parse('2026-10-01 00:01:06', 'UTC');
    $fingerprint = 'log|RuntimeException|app/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick';
    $row = ProblemFingerprint::query()->create([
        'fingerprint' => $fingerprint,
        'source' => ProblemSource::Log,
        'first_seen' => $seen,
        'last_seen' => $seen,
        'occurrences' => 1,
        'evidence' => [
            'log_excerpt' => 'first',
            'observation_times' => ['2026-10-01T00:01:06.000000Z'],
            'observation_counts' => [1],
            'counted_blocks' => [intdiv($seen->getTimestamp(), 300)],
        ],
    ]);
    $frame = base_path().'/app/Domain/Tasks/TaskScheduler.php';
    $call = 'App\\Domain\\Tasks\\TaskScheduler->tick()';
    problem_suppression_append(problem_suppression_record('RuntimeException', $frame, $call, 'req-window', 'Scheduler failed.', '2026-10-01 00:01:06'));

    expect(Artisan::call('problems:collect'))->toBe(0);

    $row->refresh();

    expect($row->occurrences)->toBe(1)
        ->and($row->evidence['observation_counts'])->toBe([2])
        ->and($row->evidence['source_path'])->toBe('app/Domain/Tasks/TaskScheduler.php');

    $evidence = $row->evidence;
    $evidence['source_path'] = 'app/Domain/Tasks/Original.php';
    $row->evidence = $evidence;
    $row->save();
    problem_suppression_append(problem_suppression_record('RuntimeException', $frame, $call, 'req-again', 'Scheduler failed.', '2026-10-01 00:02:06'));

    expect(Artisan::call('problems:collect'))->toBe(0)
        ->and($row->refresh()->occurrences)->toBe(1)
        ->and($row->evidence['observation_counts'])->toBe([3])
        ->and($row->evidence['source_path'])->toBe('app/Domain/Tasks/Original.php');
});

it('stores the log source path for a shortened fingerprint that suppression cannot read from the key', function (): void {
    problem_suppression_sandbox();
    problem_suppression_append('');
    Artisan::call('problems:collect');
    $frame = base_path().'/app/Domain/Tasks/TaskScheduler.php';
    $call = 'App\\Domain\\Tasks\\TaskScheduler->'.str_repeat('a', 240).'()';
    problem_suppression_append(problem_suppression_record(
        'RuntimeException',
        $frame,
        $call,
        'req-long',
        'Scheduler failed.',
        '2026-10-01 00:01:06',
    ));

    expect(Artisan::call('problems:collect'))->toBe(0);

    $row = ProblemFingerprint::query()->sole();

    expect($row->fingerprint)->toMatch('/\Alog#[0-9a-f]{12}\z/')
        ->and($row->fingerprint)->not->toContain('TaskScheduler')
        ->and($row->occurrences)->toBe(1)
        ->and($row->evidence['source_path'])->toBe('app/Domain/Tasks/TaskScheduler.php');
});

it('does not suppress a source path when the configured prefix is empty', function (): void {
    config()->set('orbit.problems.suppressed_fingerprints', []);
    config()->set('orbit.problems.suppressed_path_prefixes', ['']);
    problem_suppression_sandbox();
    problem_suppression_append('');
    Artisan::call('problems:collect');
    problem_suppression_append(problem_suppression_record(
        'RuntimeException',
        base_path().'/app/Infrastructure/Tasks/T3/Driver.php',
        'App\\Infrastructure\\Tasks\\T3\\Driver->run()',
        'req-empty',
        'T3 subscription ended.',
        '2026-10-01 00:01:06',
    ));

    expect(Artisan::call('problems:collect'))->toBe(0)
        ->and(ProblemFingerprint::query()->count())->toBe(1)
        ->and(ProblemFingerprint::query()->sole()->evidence['source_path'])->toBe('app/Infrastructure/Tasks/T3/Driver.php');
});

it('suppresses filing of an exact fingerprint and a path prefix and still files an unsuppressed key', function (): void {
    config()->set('orbit.problems.suppressed_fingerprints', [
        'activity|instance:clone|instance.clone_failed',
    ]);
    config()->set('orbit.problems.suppressed_path_prefixes', [
        'app/Infrastructure/Tasks/T3/',
        '/resources/noise/',
    ]);
    problem_suppression_project();

    foreach (range(1, 50) as $index) {
        problem_suppression_fingerprint('activity|noise:'.$index.'|noise.failed', 100, [
            'error_message' => 'noise',
            'paths' => ['/resources/noise/'.$index],
            ...problem_suppression_windows(10, '2026-09-01 00:00:00'),
        ], Carbon::parse('2026-09-01 00:00:00', 'UTC'));
    }

    $exact = problem_suppression_fingerprint('activity|instance:clone|instance.clone_failed', 100, [
        'error_message' => 'Clone failed',
        'paths' => ['/resources/instance:clone'],
        ...problem_suppression_windows(10, '2026-09-01 00:00:00'),
    ], Carbon::parse('2026-09-01 00:00:00', 'UTC'));
    $storedPath = problem_suppression_fingerprint(
        'log|RuntimeException|app/Domain/Kept.php:App\\Domain\\Kept->run',
        100,
        [
            'log_excerpt' => 'old path',
            'source_path' => 'app/Infrastructure/Tasks/T3/Subscription.php',
            ...problem_suppression_windows(10, '2026-09-01 00:00:00'),
        ],
        Carbon::parse('2026-09-01 00:00:00', 'UTC'),
    );
    $framePath = problem_suppression_fingerprint(
        'log|RuntimeException|app/Infrastructure/Tasks/T3/Driver.php:App\\Infrastructure\\Tasks\\T3\\Driver->run',
        100,
        [
            'log_excerpt' => 'driver down',
            ...problem_suppression_windows(10, '2026-09-01 00:00:00'),
        ],
        Carbon::parse('2026-09-01 00:00:00', 'UTC'),
    );
    $activityPath = problem_suppression_fingerprint('activity|instance:delete|instance.delete_failed', 100, [
        'error_message' => 'Delete failed',
        'paths' => ['app/Infrastructure/Tasks/T3/Driver.php'],
        ...problem_suppression_windows(10, '2026-09-01 00:00:00'),
    ], Carbon::parse('2026-09-01 00:00:00', 'UTC'));
    $fingerprintText = problem_suppression_fingerprint('activity|app/Infrastructure/Tasks/T3/noise|exit', 10, [
        'error_message' => 'The fingerprint text is not a source path.',
        'paths' => ['/resources/route:publish'],
        ...problem_suppression_windows(10, '2026-09-26 08:00:00'),
    ], Carbon::parse('2026-09-26 08:00:00', 'UTC'));
    $doctor = problem_suppression_fingerprint(
        'doctor|node.lifecycle_not_active|node|app/Infrastructure/Tasks/T3/4',
        2,
        [
            'summary' => 'Node is still provisioning.',
            'observation_times' => ['2026-09-30T10:00:00.000000Z', '2026-09-30T10:10:00.000000Z'],
            'observation_counts' => [1, 1],
        ],
        Carbon::parse('2026-09-30 10:00:00', 'UTC'),
        Carbon::parse('2026-09-30 10:10:00', 'UTC'),
    );
    $open = problem_suppression_fingerprint(
        'log|LogicException|app/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick',
        10,
        [
            'log_excerpt' => 'Scheduler failed.',
            'source_path' => 'app/Domain/Tasks/TaskScheduler.php',
            ...problem_suppression_windows(10, '2026-09-28 08:00:00'),
        ],
        Carbon::parse('2026-09-28 08:00:00', 'UTC'),
    );

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(3);

    foreach ([$exact, $storedPath, $framePath, $activityPath] as $suppressed) {
        $row = $suppressed->refresh();

        expect($row->task_group_id)->toBeNull()
            ->and($row->occurrences)->toBe(100)
            ->and($row->muted_until)->toBeNull();
    }

    expect(ProblemFingerprint::query()->where('fingerprint', 'like', 'activity|noise:%')->whereNotNull('task_group_id')->count())->toBe(0)
        ->and($fingerprintText->refresh()->task_group_id)->not->toBeNull()
        ->and($doctor->refresh()->task_group_id)->not->toBeNull()
        ->and($open->refresh()->task_group_id)->not->toBeNull()
        ->and($open->occurrences)->toBe(0)
        ->and($open->evidence)->not->toHaveKey('source_path');
});

it('suppresses a shortened log fingerprint with no source path and does not mute or file it', function (): void {
    $project = problem_suppression_project();
    $unchanged = problem_suppression_cancelled($project->id, 'Unplaced');
    $known = problem_suppression_cancelled($project->id, 'Known path');
    $missing = problem_suppression_log('log#'.substr(hash('sha256', 'missing-path'), 0, 12), [
        'log_excerpt' => 'no path',
        ...problem_suppression_windows(10),
    ], $unchanged->id);
    $placed = problem_suppression_log('log#'.substr(hash('sha256', 'has-path'), 0, 12), [
        'log_excerpt' => 'placed',
        'source_path' => 'app/Infrastructure/Tasks/T3/Driver.php',
        ...problem_suppression_windows(10, '2026-09-29 08:00:00'),
    ], $known->id);
    $innocent = problem_suppression_log('log#'.substr(hash('sha256', 'innocent-path'), 0, 12), [
        'log_excerpt' => 'kept',
        'source_path' => 'app/Domain/Tasks/TaskScheduler.php',
        ...problem_suppression_windows(10, '2026-09-28 08:00:00'),
    ]);
    $open = problem_suppression_fingerprint('activity|route:publish|route.publish_failed', 10, [
        'error_message' => 'Publish failed',
        'paths' => ['/resources/route:publish'],
        ...problem_suppression_windows(10, '2026-09-27 08:00:00'),
    ], Carbon::parse('2026-09-27 08:00:00', 'UTC'));

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(4);

    $missing->refresh();
    $placed->refresh();

    expect($missing->task_group_id)->toBe($unchanged->id)
        ->and($missing->muted_until)->toBeNull()
        ->and($missing->occurrences)->toBe(10)
        ->and($missing->evidence['log_excerpt'])->toBe('no path')
        ->and($missing->evidence['observation_times'])->not->toBeEmpty()
        ->and($placed->task_group_id)->toBe($known->id)
        ->and($placed->filed_at)->toBeNull()
        ->and($placed->muted_until?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-30 08:30:00')
        ->and($placed->occurrences)->toBe(0)
        ->and($placed->evidence)->not->toHaveKey('source_path')
        ->and($placed->evidence)->not->toHaveKey('log_excerpt')
        ->and($innocent->refresh()->task_group_id)->not->toBeNull()
        ->and($innocent->occurrences)->toBe(0)
        ->and($innocent->evidence)->not->toHaveKey('source_path')
        ->and($open->refresh()->task_group_id)->not->toBeNull();
});

it('does not suppress a shortened log fingerprint when no path prefix is configured', function (): void {
    config()->set('orbit.problems.suppressed_fingerprints', []);
    config()->set('orbit.problems.suppressed_path_prefixes', []);
    problem_suppression_project();
    $short = problem_suppression_log('log#'.substr(hash('sha256', 'empty-prefixes'), 0, 12), [
        'log_excerpt' => 'shortened',
        ...problem_suppression_windows(10),
    ]);
    $frame = problem_suppression_fingerprint(
        'log|RuntimeException|app/Infrastructure/Tasks/T3/Driver.php:App\\Infrastructure\\Tasks\\T3\\Driver->run',
        10,
        [
            'log_excerpt' => 'driver',
            ...problem_suppression_windows(10, '2026-09-29 08:00:00'),
        ],
        Carbon::parse('2026-09-29 08:00:00', 'UTC'),
    );

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(2)
        ->and($short->refresh()->task_group_id)->not->toBeNull()
        ->and($frame->refresh()->task_group_id)->not->toBeNull();
});

it('still applies the 14 day cancel mute when a fingerprint is suppressed', function (): void {
    config()->set('orbit.problems.suppressed_fingerprints', [
        'activity|instance:clone|instance.clone_failed',
    ]);
    $project = problem_suppression_project();
    $task = problem_suppression_cancelled($project->id, 'Cancelled noise');
    $fingerprint = problem_suppression_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        'request_ids' => ['req-1'],
        'assistance_task_ids' => [99],
        ...problem_suppression_windows(10),
    ]);
    $fingerprint->task_group_id = $task->id;
    $fingerprint->save();

    expect(Artisan::call('problems:file'))->toBe(0);

    $muted = $fingerprint->refresh();

    expect(Task::topLevel()->count())->toBe(1)
        ->and($muted->task_group_id)->toBe($task->id)
        ->and($muted->filed_at)->toBeNull()
        ->and($muted->muted_until?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-30 08:30:00')
        ->and($muted->occurrences)->toBe(0)
        ->and($muted->evidence)->not->toHaveKey('request_ids')
        ->and($muted->evidence['assistance_task_ids'] ?? null)->toBe([99]);
});

function problem_suppression_sandbox(): void
{
    $path = TestOrbitHome::scratch('problem-suppression');
    mkdir($path.'/logs', 0700, true);
    app()->useStoragePath($path);
    app(TaskExtensionState::class)->enable();
}

function problem_suppression_activity(string $command, ?string $errorCode, ?int $exitCode, ?string $requestId = null): Activity
{
    return Activity::query()->create([
        'log_name' => 'commands',
        'description' => $command,
        'request_id' => $requestId ?? (string) Str::uuid(),
        'command' => $command,
        'status' => 'failed',
        'exit_code' => $exitCode,
        'error_code' => $errorCode,
        'properties' => [
            'path' => '/resources/'.$command,
            'error_message' => 'token=supersecretvalue',
        ],
    ]);
}

function problem_suppression_activity_at(
    string $command,
    ?string $errorCode,
    ?int $exitCode,
    Carbon $createdAt,
    ?string $requestId = null,
): Activity {
    $activity = problem_suppression_activity($command, $errorCode, $exitCode, $requestId);
    $activity->forceFill(['created_at' => $createdAt])->save();

    return $activity;
}

function problem_suppression_append(string $contents): void
{
    $path = storage_path('logs/laravel.log');
    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }

    file_put_contents($path, $contents, FILE_APPEND);
}

function problem_suppression_record(
    string $class,
    string $framePath,
    string $call,
    string $requestId,
    string $message,
    ?string $recordedAt = null,
): string {
    $loggedClass = str_replace('\\', '\\\\', $class);
    $loggedCall = str_replace('\\', '\\\\', $call);
    $timezone = config('app.timezone');
    $stamp = $recordedAt ?? now()->timezone(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC')->format('Y-m-d H:i:s');

    return '['.$stamp.'] testing.ERROR: '.$message.' {"exception":"[object] ('.$loggedClass.'(code: 0): '.$message.' at '.$framePath.':1)
[stacktrace]
#0 '.$framePath.'(1): '.$loggedCall.'
#1 /tmp/vendor/framework.php(1): ignore()
#2 {main}
","request_id":"'.$requestId.'"} 
';
}

function problem_suppression_project(): Project
{
    app(TaskExtensionState::class)->enable();

    return Project::query()->create([
        'name' => 'Orbit',
        'slug' => 'orbit',
        'repository_url' => 'https://example.test/orbit.git',
        'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
}

/**
 * @return array{observation_times: list<string>, observation_counts: list<int>}
 */
function problem_suppression_windows(int $count, string $start = '2026-09-30 10:00:00'): array
{
    $origin = Carbon::parse($start, 'UTC');
    $times = [];

    for ($index = 0; $index < $count; $index++) {
        $times[] = $origin->copy()->addMinutes($index * 5)->format('Y-m-d\TH:i:s.u\Z');
    }

    return [
        'observation_times' => $times,
        'observation_counts' => array_fill(0, $count, 1),
    ];
}

/**
 * @param  array<string, mixed>  $evidence
 */
function problem_suppression_fingerprint(
    string $fingerprint,
    int $occurrences,
    array $evidence = [],
    ?Carbon $firstSeen = null,
    ?Carbon $lastSeen = null,
): ProblemFingerprint {
    $source = match (true) {
        str_starts_with($fingerprint, 'doctor|') => ProblemSource::Doctor,
        str_starts_with($fingerprint, 'log|') => ProblemSource::Log,
        str_starts_with($fingerprint, 'assist|') => ProblemSource::Assist,
        default => ProblemSource::Activity,
    };

    return ProblemFingerprint::query()->create([
        'fingerprint' => $fingerprint,
        'source' => $source,
        'first_seen' => $firstSeen ?? Carbon::parse('2026-09-30 10:00:00'),
        'last_seen' => $lastSeen ?? Carbon::parse('2026-09-30 10:14:00'),
        'occurrences' => $occurrences,
        'evidence' => $evidence,
    ]);
}

/**
 * @param  array<string, mixed>  $evidence
 */
function problem_suppression_log(string $fingerprint, array $evidence, ?int $taskGroupId = null): ProblemFingerprint
{
    return ProblemFingerprint::query()->create([
        'fingerprint' => $fingerprint,
        'source' => ProblemSource::Log,
        'first_seen' => Carbon::parse('2026-09-30 10:00:00', 'UTC'),
        'last_seen' => Carbon::parse('2026-09-30 10:45:00', 'UTC'),
        'occurrences' => 10,
        'evidence' => $evidence,
        'task_group_id' => $taskGroupId,
    ]);
}

function problem_suppression_cancelled(int $projectId, string $title): Task
{
    $task = Task::topLevel()->create([
        'project_id' => $projectId,
        'title' => $title,
        'brief' => 'The operator cancelled this group.',
        'status' => TaskGroupStatus::Cancelled,
    ]);
    Task::topLevel()->whereKey($task->id)->update(['updated_at' => '2026-09-16 08:30:00']);

    return $task;
}
