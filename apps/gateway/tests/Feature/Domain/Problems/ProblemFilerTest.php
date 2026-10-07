<?php

declare(strict_types=1);

use App\Domain\Problems\ProblemSource;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

it('files a repro-first group when a fingerprint is over the threshold', function (): void {
    $project = problem_filer_project();
    $fingerprint = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        'request_ids' => ['req-1', 'req-2'],
        'activity_ids' => [11, 12],
        'paths' => ['/resources/instance:clone'],
        ...problem_filer_windows(10),
    ], Carbon::parse('2026-09-30 10:00:00', 'UTC'), Carbon::parse('2026-09-30 10:45:00', 'UTC'));

    expect(Artisan::call('problems:file'))->toBe(0);

    $group = Task::topLevel()->sole();
    $subtasks = $group->tasks()->get();
    $filed = $fingerprint->refresh();

    expect($group->project_id)->toBe($project->id)
        ->and($group->status)->toBe(TaskGroupStatus::Backlog)
        ->and($group->title)->toBe('instance:clone failed with instance.clone_failed')
        ->and($group->brief)->toBe(problem_filer_brief())
        ->and($subtasks)->toHaveCount(2)
        ->and($subtasks[0]->title)->toBe('Document the owning page')
        ->and($subtasks[0]->brief)->toBe('Clone failed')
        ->and($subtasks[0]->deliverables)->toBe([[
            'id' => 'docs',
            'type' => 'review',
            'description' => 'The owning page matches the fix, or no page changes.',
        ]])
        ->and($subtasks[1]->title)->toBe('Reproduce the failure and fix it')
        ->and($subtasks[1]->brief)->toBe('Clone failed')
        ->and($subtasks[1]->deliverables)->toBe([[
            'id' => 'test',
            'type' => 'review',
            'description' => 'Replace this deliverable with a scoped fails_on_base command before moving the task to Todo.',
        ]])
        ->and($filed->task_group_id)->toBe($group->id)
        ->and($filed->filed_at)->not->toBeNull()
        ->and($filed->muted_until)->toBeNull()
        ->and($filed->occurrences)->toBe(0)
        ->and($filed->first_seen)->toBeNull()
        ->and($filed->last_seen)->toBeNull()
        ->and($filed->evidence)->not->toHaveKey('request_ids')
        ->and($filed->evidence)->not->toHaveKey('activity_ids')
        ->and($filed->evidence)->not->toHaveKey('paths')
        ->and($filed->evidence)->not->toHaveKey('observation_times')
        ->and($filed->evidence)->not->toHaveKey('observation_counts')
        ->and($filed->evidence)->not->toHaveKey('counted_blocks')
        ->and($filed->evidence['error_message'] ?? null)->toBe('Clone failed');

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1);
});

it('files a filed template with occurrence history and a review placeholder instead of an unscoped command', function (): void {
    problem_filer_project();
    problem_filer_fingerprint(
        'log|RuntimeException|app/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick',
        3,
        [
            'log_excerpt' => 'T3 subscription ended.',
            'request_ids' => ['req-1', 'req-2'],
            'observation_times' => [
                '2026-10-01T00:16:06.000000Z',
                '2026-10-01T00:01:06.000000Z',
                '2026-10-01T00:06:06.000000Z',
            ],
            'observation_counts' => [2, 129, 1],
        ],
        Carbon::parse('2026-10-01 00:01:06', 'UTC'),
        Carbon::parse('2026-10-01 00:16:06', 'UTC'),
    );

    expect(Artisan::call('problems:file'))->toBe(0);

    $group = Task::topLevel()->sole();
    $subtasks = $group->tasks()->get();

    expect($group->status)->toBe(TaskGroupStatus::Backlog)
        ->and($group->brief)->toBe(implode("\n\n", [
            'Filed by the outer loop.',
            "Symptom\nT3 subscription ended.",
            "Fingerprint\nlog|RuntimeException|app/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick",
            "First seen\n2026-10-01 00:01:06 UTC",
            "Last seen\n2026-10-01 00:16:06 UTC",
            "Count\n3",
            "Occurrences\n2026-10-01 00:01:06 UTC 129\n2026-10-01 00:06:06 UTC 1\n2026-10-01 00:16:06 UTC 2",
            "Evidence\nRequest ids: req-1, req-2\nLog excerpt: T3 subscription ended.",
            "Suspected entry point\napp/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick",
        ]))
        ->and($subtasks)->toHaveCount(2)
        ->and($subtasks[0]->title)->toBe('Document the owning page')
        ->and($subtasks[1]->title)->toBe('Reproduce the failure and fix it')
        ->and($subtasks[1]->deliverables)->toBe([[
            'id' => 'test',
            'type' => 'review',
            'description' => 'Replace this deliverable with a scoped fails_on_base command before moving the task to Todo.',
        ]]);

    foreach ($subtasks as $subtask) {
        expect($subtask->brief)->toBe('T3 subscription ended.');

        foreach ($subtask->deliverables as $deliverable) {
            expect($deliverable)->not->toHaveKey('command')
                ->not->toHaveKey('directory')
                ->not->toHaveKey('fails_on_base')
                ->not->toHaveKey('paths');
        }
    }
});

it('renders the known source path in the filed template for a shortened log key before clearing it', function (): void {
    problem_filer_project();
    $sourcePath = 'app/Domain/Tasks/TaskScheduler.php';
    $key = 'log|'.str_repeat('VeryLongNamespace\\', 20).'RuntimeException|'.$sourcePath.':App\\Domain\\Tasks\\TaskScheduler->tick';
    $shortened = 'log#'.substr(hash('sha256', $key), 0, 12);
    $fingerprint = problem_filer_fingerprint($shortened, 4, [
        'source_path' => $sourcePath,
        'log_excerpt' => 'T3 subscription ended.',
        'request_ids' => ['req-shortened'],
        ...problem_filer_windows(4, '2026-10-01 00:00:01'),
    ], Carbon::parse('2026-10-01 00:00:01', 'UTC'), Carbon::parse('2026-10-01 00:15:01', 'UTC'));

    expect(Artisan::call('problems:file'))->toBe(0);

    $group = Task::topLevel()->sole();

    expect($group->status)->toBe(TaskGroupStatus::Backlog)
        ->and($group->brief)->toContain(
            "Fingerprint\n{$shortened}",
            "Occurrences\n2026-10-01 00:00:01 UTC 1\n2026-10-01 00:05:01 UTC 1\n2026-10-01 00:10:01 UTC 1\n2026-10-01 00:15:01 UTC 1",
            'Request ids: req-shortened',
            "Suspected entry point\n{$sourcePath}",
        )
        ->not->toContain("Suspected entry point\n{$shortened}")
        ->and($fingerprint->refresh()->task_group_id)->toBe($group->id)
        ->and($fingerprint->evidence)->not->toHaveKey('source_path');
});

it('keeps the newest twenty UTC windows in the filed template', function (): void {
    problem_filer_project();
    $windows = problem_filer_windows(21);
    problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 21, [
        'error_message' => 'Clone failed',
        'observation_times' => array_reverse($windows['observation_times']),
        'observation_counts' => array_reverse(range(1, 21)),
    ]);

    expect(Artisan::call('problems:file'))->toBe(0);

    $brief = Task::topLevel()->sole()->brief;
    $history = explode("\n\nEvidence\n", explode("Occurrences\n", $brief)[1])[0];

    expect(explode("\n", $history))->toHaveCount(20)
        ->and($history)->toStartWith('2026-09-30 10:05:00 UTC 2')
        ->toEndWith('2026-09-30 11:40:00 UTC 21')
        ->not->toContain('2026-09-30 10:00:00 UTC 1')
        ->and($brief)->toContain("Evidence\nnone")
        ->not->toContain('Request ids:');
});

it('shows none in the filed template when there is no valid occurrence history', function (mixed $times, mixed $counts): void {
    problem_filer_project();
    problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        'observation_times' => $times,
        'observation_counts' => $counts,
    ]);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->sole()->brief)->toContain("Occurrences\nnone\n\nEvidence\nnone");
})->with([
    'no times' => [null, []],
    'no counts' => [[], null],
    'empty sample' => [[], []],
    'invalid sample' => [
        ['not-a-timestamp', '', 42, '2026-09-30T10:00:00Z', '2026-09-30T10:05:00Z', '2026-09-30T10:10:00Z'],
        [1, 1, 1, 0, '1'],
    ],
]);

it('converts occurrence times to UTC in the filed template without changing their counts', function (): void {
    problem_filer_project();
    problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        'observation_times' => ['2026-09-30T12:00:01+02:00', '2026-09-30T11:05:01+01:00'],
        'observation_counts' => [129, 2],
    ]);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->sole()->brief)->toContain("Occurrences\n2026-09-30 10:00:01 UTC 129\n2026-09-30 10:05:01 UTC 2");
});

it('files a repro-first group when doctor sees the same issue twice', function (): void {
    problem_filer_project();
    $fingerprint = problem_filer_fingerprint('doctor|node.lifecycle_not_active|node|4', 2, [
        'summary' => 'Node 4 is still provisioning.',
        'expected' => 'active',
        'observed' => 'provisioning',
        'observation_times' => [
            '2026-09-30T10:00:00Z',
            '2026-09-30T10:10:00Z',
        ],
        'observation_counts' => [1, 1],
    ], Carbon::parse('2026-09-30 10:00:00'), Carbon::parse('2026-09-30 10:10:00'));

    expect(Artisan::call('problems:file'))->toBe(0);

    $group = Task::topLevel()->sole();

    expect($group->status)->toBe(TaskGroupStatus::Backlog)
        ->and($group->title)->toBe('Doctor node.lifecycle_not_active on node 4')
        ->and($group->brief)->toContain('Symptom', 'Node 4 is still provisioning.', 'Expected: active', 'Observed: provisioning')
        ->and($group->brief)->toContain('Suspected entry point', 'node 4 node.lifecycle_not_active')
        ->and($fingerprint->refresh()->task_group_id)->toBe($group->id)
        ->and($group->tasks()->get()[1]->deliverables[0]['type'] ?? null)->toBe('review');
});

it('files a repro-first group when three hits land in two quarter hours', function (): void {
    problem_filer_project();
    $fingerprint = problem_filer_fingerprint('activity|route:publish|route.publish_failed', 3, [
        'error_message' => 'Publish failed',
        'observation_times' => [
            '2026-09-30T10:00:00Z',
            '2026-09-30T10:07:00Z',
            '2026-09-30T10:15:00Z',
        ],
        'observation_counts' => [1, 1, 1],
    ]);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($fingerprint->refresh()->task_group_id)->not->toBeNull();
});

it('files a repro-first group for nothing below the threshold', function (): void {
    problem_filer_project();
    $belowBurst = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 2, [
        'error_message' => 'Clone failed',
        ...problem_filer_windows(2),
    ]);
    $sameQuarter = problem_filer_fingerprint('activity|instance:delete|instance.delete_failed', 3, [
        'error_message' => 'Delete failed',
        ...problem_filer_windows(3),
    ]);
    $doctor = problem_filer_fingerprint('doctor|node.lifecycle_not_active|node|4', 2, [
        'summary' => 'Node 4 is still provisioning.',
        'observation_times' => ['2026-09-30T10:00:00Z', '2026-09-30T10:09:00Z'],
        'observation_counts' => [1, 1],
    ], Carbon::parse('2026-09-30 10:00:00'), Carbon::parse('2026-09-30 10:09:00'));

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(0)
        ->and($belowBurst->refresh()->occurrences)->toBe(2)
        ->and($belowBurst->task_group_id)->toBeNull()
        ->and($sameQuarter->refresh()->task_group_id)->toBeNull()
        ->and($sameQuarter->occurrences)->toBe(3)
        ->and($doctor->refresh()->occurrences)->toBe(2)
        ->and($doctor->task_group_id)->toBeNull();
});

it('files a repro-first group for nothing after one log burst', function (): void {
    problem_filer_project();
    $fingerprint = problem_filer_fingerprint(
        'log|RuntimeException|app/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick',
        1,
        [
            'log_excerpt' => 'T3 subscription ended.',
            'observation_times' => ['2026-10-01T00:01:06.000000Z'],
            'observation_counts' => [129],
        ],
        Carbon::parse('2026-10-01 00:01:06', 'UTC'),
        Carbon::parse('2026-10-01 00:01:06', 'UTC'),
    );

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(0)
        ->and($fingerprint->refresh()->occurrences)->toBe(1)
        ->and($fingerprint->task_group_id)->toBeNull()
        ->and($fingerprint->evidence['observation_counts'])->toBe([129]);
});

it('drops a legacy log burst read across several collector times without filing it', function (): void {
    $this->travelTo(Carbon::parse('2026-10-02 16:00:00', 'UTC'));
    $project = problem_filer_project();
    $task = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Already filed',
        'brief' => 'The operator has not finished with this group.',
        'status' => TaskGroupStatus::Backlog,
    ]);
    $mutedUntil = Carbon::parse('2026-10-14 12:00:00', 'UTC');
    $filedAt = Carbon::parse('2026-10-01 01:00:00', 'UTC');
    $linked = problem_filer_fingerprint(
        'log|RuntimeException|app/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick',
        129,
        [
            'log_excerpt' => 'T3 subscription ended.',
            'assistance_task_ids' => [44],
            'observation_times' => [
                '2026-10-02T15:00:00.000000Z',
                '2026-10-02T15:10:00.000000Z',
                '2026-10-02T15:20:00.000000Z',
            ],
        ],
        Carbon::parse('2026-10-02 15:00:00', 'UTC'),
        Carbon::parse('2026-10-02 15:20:00', 'UTC'),
    );
    $linked->task_group_id = $task->id;
    $linked->muted_until = $mutedUntil;
    $linked->filed_at = $filedAt;
    $linked->save();
    $unparsed = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 129, [
        'error_message' => 'Clone failed',
        'observation_times' => ['not-a-timestamp'],
    ]);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($linked->refresh()->task_group_id)->toBe($task->id)
        ->and($linked->muted_until?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-14 12:00:00')
        ->and($linked->filed_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-01 01:00:00')
        ->and($linked->occurrences)->toBe(0)
        ->and($linked->first_seen)->toBeNull()
        ->and($linked->last_seen)->toBeNull()
        ->and($linked->evidence['assistance_task_ids'])->toBe([44])
        ->and($linked->evidence)->not->toHaveKey('observation_times')
        ->and($linked->evidence['observation_counts'])->toBe([])
        ->and($linked->evidence['counted_blocks'])->toBe([])
        ->and($unparsed->refresh()->occurrences)->toBe(0)
        ->and($unparsed->task_group_id)->toBeNull()
        ->and($unparsed->evidence['counted_blocks'])->toBe([]);
});

it('files a legacy doctor episode when two collector times are ten minutes apart', function (): void {
    problem_filer_project();
    problem_filer_fingerprint('doctor|node.lifecycle_not_active|node|4', 2, [
        'summary' => 'Node 4 is still provisioning.',
        'observation_times' => ['2026-09-30T10:00:00Z', '2026-09-30T10:10:00Z'],
    ], Carbon::parse('2026-09-30 10:00:00', 'UTC'), Carbon::parse('2026-09-30 10:10:00', 'UTC'));

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and(Task::topLevel()->sole()->brief)->toContain("Occurrences\n2026-09-30 10:00:00 UTC 1\n2026-09-30 10:10:00 UTC 1");
});

it('files a repro-first group for nothing while its task is still open', function (string $status): void {
    $project = problem_filer_project();
    $task = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Already filed',
        'brief' => 'The operator has not finished with this group.',
        'status' => $status,
    ]);
    $fingerprint = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        ...problem_filer_windows(10),
    ]);
    $fingerprint->task_group_id = $task->id;
    $fingerprint->save();

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($fingerprint->refresh()->task_group_id)->toBe($task->id)
        ->and($fingerprint->filed_at)->toBeNull()
        ->and($fingerprint->muted_until)->toBeNull()
        ->and($fingerprint->occurrences)->toBe(10);
})->with(['backlog', 'todo', 'reserved', 'running', 'reviewing', 'settling']);

it('files a repro-first group for nothing muted after a cancel', function (): void {
    $project = problem_filer_project();
    $task = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Cancelled noise',
        'brief' => 'The operator cancelled this group.',
        'status' => TaskGroupStatus::Cancelled,
    ]);
    Task::topLevel()->whereKey($task->id)->update(['updated_at' => '2026-09-16 08:30:00']);
    $fingerprint = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        'request_ids' => ['req-1'],
        'log_excerpt' => 'Clone failed',
        'assistance_task_ids' => [99],
        ...problem_filer_windows(10),
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
        ->and($muted->first_seen)->toBeNull()
        ->and($muted->evidence)->not->toHaveKey('request_ids')
        ->and($muted->evidence)->not->toHaveKey('log_excerpt')
        ->and($muted->evidence)->not->toHaveKey('observation_times')
        ->and($muted->evidence['assistance_task_ids'] ?? null)->toBe([99]);

    $this->travel(1)->days();

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($fingerprint->refresh()->muted_until?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-30 08:30:00');
});

it('files a repro-first group for nothing while a cancel mute is still in force', function (): void {
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00', 'UTC'));
    $project = problem_filer_project();
    $task = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Cancelled noise',
        'brief' => 'The operator cancelled this group.',
        'status' => TaskGroupStatus::Cancelled,
    ]);
    $mutedUntil = Carbon::parse('2026-10-14 12:00:00', 'UTC');
    $fingerprint = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed again',
        ...problem_filer_windows(10),
    ], Carbon::parse('2026-09-30 10:00:00', 'UTC'), Carbon::parse('2026-09-30 10:45:00', 'UTC'));
    $fingerprint->task_group_id = $task->id;
    $fingerprint->muted_until = $mutedUntil;
    $fingerprint->save();

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($fingerprint->refresh()->task_group_id)->toBe($task->id)
        ->and($fingerprint->filed_at)->toBeNull()
        ->and($fingerprint->occurrences)->toBe(10)
        ->and($fingerprint->muted_until?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-14 12:00:00');

    $this->travelTo($mutedUntil->copy()->addSecond());

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(2)
        ->and($fingerprint->refresh()->task_group_id)->not->toBe($task->id)
        ->and($fingerprint->muted_until)->toBeNull()
        ->and($fingerprint->occurrences)->toBe(0);
});

it('files a repro-first group for nothing muted after a completed or failed task', function (string $status): void {
    $project = problem_filer_project();
    $task = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Ended',
        'brief' => 'This group already ended.',
        'status' => $status,
    ]);
    Task::topLevel()->whereKey($task->id)->update(['updated_at' => '2026-09-20 12:00:00']);
    $fingerprint = problem_filer_fingerprint('log|RuntimeException|app/Domain/Tasks/TaskScheduler.php:tick', 10, [
        'log_excerpt' => 'The scheduler blew up',
        ...problem_filer_windows(10),
    ]);
    $fingerprint->task_group_id = $task->id;
    $fingerprint->save();

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($fingerprint->refresh()->muted_until?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-27 12:00:00')
        ->and($fingerprint->occurrences)->toBe(0)
        ->and($fingerprint->filed_at)->toBeNull();
})->with(['completed', 'failed']);

it('files a repro-first group for the busiest fingerprints and skips the fourth filing that day', function (): void {
    problem_filer_project();
    foreach (['activity|old:one|old.failed'] as $fingerprint) {
        ProblemFingerprint::query()->create([
            'fingerprint' => $fingerprint,
            'source' => ProblemSource::Activity,
            'occurrences' => 0,
            'evidence' => [],
            'filed_at' => now(),
        ]);
    }

    $busiest = problem_filer_fingerprint('activity|instance:deploy|instance.deploy_failed', 30, [
        'error_message' => 'Deploy failed',
        ...problem_filer_marker(),
    ], Carbon::parse('2026-09-30 11:00:00'));
    $earlier = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        ...problem_filer_marker(),
    ], Carbon::parse('2026-09-30 09:00:00'));
    $later = problem_filer_fingerprint('activity|instance:delete|instance.delete_failed', 10, [
        'error_message' => 'Delete failed',
        ...problem_filer_marker(),
    ], Carbon::parse('2026-09-30 10:30:00'));

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(2)
        ->and($busiest->refresh()->task_group_id)->not->toBeNull()
        ->and($earlier->refresh()->task_group_id)->not->toBeNull()
        ->and($later->refresh()->task_group_id)->toBeNull()
        ->and($later->filed_at)->toBeNull()
        ->and($later->occurrences)->toBe(10)
        ->and(Task::topLevel()->find($busiest->task_group_id)?->brief)->toContain("Evidence\nnone")
        ->and(Task::topLevel()->find($earlier->task_group_id)?->brief)->toContain("Evidence\nnone");
});

it('preserves occurrence history in the filed template when dropping Evidence lines that do not fit', function (): void {
    problem_filer_project();
    $path = str_repeat('p', 9000);
    problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        'paths' => [$path],
        ...problem_filer_windows(10),
    ]);

    expect(Artisan::call('problems:file'))->toBe(0);

    $brief = Task::topLevel()->sole()->brief;

    expect(mb_strlen($brief))->toBeLessThanOrEqual(8000)
        ->and($brief)->toContain("Evidence\nnone")
        ->and($brief)->not->toContain($path)
        ->and($brief)->toContain('Suspected entry point', "Occurrences\n2026-09-30 10:00:00 UTC 1", "2026-09-30 10:45:00 UTC 1\n\nEvidence");
});

it('files a repro-first group for nothing when the orbit project is missing', function (): void {
    app(TaskExtensionState::class)->enable();
    $fingerprint = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        ...problem_filer_marker(),
    ]);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(0)
        ->and($fingerprint->refresh()->task_group_id)->toBeNull()
        ->and($fingerprint->filed_at)->toBeNull()
        ->and($fingerprint->occurrences)->toBe(10);
});

it('does not file problems while tasks are disabled', function (): void {
    Project::query()->create([
        'name' => 'Orbit',
        'slug' => 'orbit',
        'repository_url' => 'https://example.test/orbit.git',
        'default_branch' => 'main',
    ]);
    $fingerprint = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
    ]);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(0)
        ->and($fingerprint->refresh()->occurrences)->toBe(10);
});

it('files a release alert on its first occurrence and ahead of higher counts', function (): void {
    problem_filer_project();
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00', 'UTC'));

    foreach (['a', 'b', 'c'] as $suffix) {
        problem_filer_fingerprint("activity|instance:clone|instance.clone_{$suffix}_failed", 10, [
            'error_message' => 'Clone failed',
            ...problem_filer_windows(10),
        ]);
    }

    $release = problem_filer_fingerprint('release|rollout_halted|fleet|'.str_repeat('a', 40), 1, [
        'summary' => 'Node beast failed self-update.',
        'release_repository' => 'nckrtl/orbit',
        ...problem_filer_windows(1),
    ]);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(3)
        ->and($release->refresh()->task_group_id)->not->toBeNull();

    $task = Task::topLevel()->findOrFail($release->task_group_id);

    expect($task->title)->toBe('Rollout halted for fleet at aaaaaaaaaaaa')
        ->and($task->brief)->toContain("Symptom\nNode beast failed self-update.")
        ->and($task->brief)->toContain("Suspected entry point\nnckrtl/orbit@".str_repeat('a', 40));
});

it('does not file a release fingerprint without an occurrence', function (): void {
    problem_filer_project();
    problem_filer_fingerprint('release|release_failed|gateway|'.str_repeat('b', 40), 0, ['summary' => 'Smoke failed.']);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(0);
});

function problem_filer_brief(): string
{
    $history = implode("\n", [
        '2026-09-30 10:00:00 UTC 1',
        '2026-09-30 10:05:00 UTC 1',
        '2026-09-30 10:10:00 UTC 1',
        '2026-09-30 10:15:00 UTC 1',
        '2026-09-30 10:20:00 UTC 1',
        '2026-09-30 10:25:00 UTC 1',
        '2026-09-30 10:30:00 UTC 1',
        '2026-09-30 10:35:00 UTC 1',
        '2026-09-30 10:40:00 UTC 1',
        '2026-09-30 10:45:00 UTC 1',
    ]);

    return implode("\n\n", [
        'Filed by the outer loop.',
        "Symptom\nClone failed",
        "Fingerprint\nactivity|instance:clone|instance.clone_failed",
        "First seen\n2026-09-30 10:00:00 UTC",
        "Last seen\n2026-09-30 10:45:00 UTC",
        "Count\n10",
        "Occurrences\n{$history}",
        "Evidence\nRequest ids: req-1, req-2\nActivity ids: 11, 12\nPaths: /resources/instance:clone",
        "Suspected entry point\ninstance:clone",
    ]);
}

/** @return array{observation_counts: list<int>, counted_blocks: list<int>} */
function problem_filer_marker(): array
{
    return [
        'observation_counts' => [],
        'counted_blocks' => [],
    ];
}

/**
 * @return array{observation_times: list<string>, observation_counts: list<int>}
 */
function problem_filer_windows(int $count, string $start = '2026-09-30 10:00:00'): array
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

function problem_filer_project(): Project
{
    app(TaskExtensionState::class)->enable();

    return Project::query()->create([
        'name' => 'Orbit',
        'slug' => 'orbit',
        'repository_url' => 'https://example.test/orbit.git',
        'default_branch' => 'main',
    ]);
}

/**
 * @param  array<string, mixed>  $evidence
 */
function problem_filer_fingerprint(
    string $fingerprint,
    int $occurrences,
    array $evidence = [],
    ?Carbon $firstSeen = null,
    ?Carbon $lastSeen = null,
): ProblemFingerprint {
    $source = match (true) {
        str_starts_with($fingerprint, 'doctor|') => ProblemSource::Doctor,
        str_starts_with($fingerprint, 'log|') || str_starts_with($fingerprint, 'log#') => ProblemSource::Log,
        str_starts_with($fingerprint, 'assist|') => ProblemSource::Assist,
        str_starts_with($fingerprint, 'release|') => ProblemSource::Release,
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
