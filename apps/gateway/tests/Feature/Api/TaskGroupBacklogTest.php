<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;

beforeEach(function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'backlog-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.90',
        'wireguard_ip' => '10.44.0.90',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $this->appRecord = Project::query()->create([
        'name' => 'Backlog demo',
        'slug' => 'backlog-demo',
        'repository_url' => 'git@example.test:backlog-demo.git',
        'default_branch' => 'main',
    ]);
    $this->provisioning = new class implements InstanceProvisioning
    {
        public int $calls = 0;

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            $this->calls++;

            return null;
        }
    };
    app()->instance(InstanceProvisioning::class, $this->provisioning);
});

/**
 * @param  list<string>  $subtasks
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function backlog_group(mixed $test, array $subtasks = ['One', 'Two', 'Three'], array $extra = []): array
{
    return $test->postJson('/api/v1/task-groups', [
        'project_id' => $test->appRecord->id,
        'title' => 'Backlog feature',
        'brief' => 'Prepare before running.',
        'tasks' => array_map(static fn (string $title): array => [
            'title' => $title,
            'brief' => "{$title} brief.",
            'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => "{$title} is done."]],
        ], $subtasks),
        ...$extra,
    ])->assertCreated()->json('data');
}

/**
 * @return array{id: string, type: string, description: string, command: string, directory: string}
 */
function php_test_deliverable(string $file, string $id = 'export-test'): array
{
    return [
        'id' => $id,
        'type' => 'command',
        'description' => 'Test the export',
        'command' => 'vendor/bin/pest '.escapeshellarg($file),
        'directory' => 'apps/gateway',
    ];
}

/** @return list<string> */
function backlog_order(int $groupId): array
{
    /** @var list<string> */
    return Task::query()->where('parent_id', $groupId)->orderBy('position')->pluck('title')->all();
}

it('stores a new group in backlog and does not claim it', function (): void {
    $group = backlog_group($this);

    expect($group['status'])->toBe('backlog')
        ->and($group['tasks'][0]['status'])->toBe('todo')
        ->and($this->provisioning->calls)->toBe(0);
});

it('claims a group created in todo', function (): void {
    $group = backlog_group($this, extra: ['status' => 'todo']);

    expect($group['status'])->toBe('todo')
        ->and($this->provisioning->calls)->toBe(1);
});

it('refuses todo for a group without subtasks', function (): void {
    $this->postJson('/api/v1/task-groups', [
        'project_id' => $this->appRecord->id,
        'title' => 'Empty',
        'brief' => 'No subtasks.',
        'status' => 'todo',
    ])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.no_subtasks');

    expect(Task::topLevel()->count())->toBe(0);

    $group = backlog_group($this, []);

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'todo'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'tasks.no_subtasks');

    expect(Task::topLevel()->findOrFail($group['id'])->status)->toBe(TaskGroupStatus::Backlog)
        ->and($this->provisioning->calls)->toBe(0);
});

it('moves a group between backlog and todo and claims it on todo', function (): void {
    $group = backlog_group($this);

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'todo'])
        ->assertOk()
        ->assertJsonPath('data.status', 'todo');

    expect($this->provisioning->calls)->toBe(1);

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'backlog'])
        ->assertOk()
        ->assertJsonPath('data.status', 'backlog');

    expect($this->provisioning->calls)->toBe(1);
});

it('changes the title and brief only in backlog', function (): void {
    $group = backlog_group($this);

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['title' => 'Renamed', 'brief' => 'New brief.'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed')
        ->assertJsonPath('data.brief', 'New brief.');

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'todo'])->assertOk();

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['title' => 'Too late'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.not_in_backlog');

    expect(Task::topLevel()->findOrFail($group['id'])->title)->toBe('Renamed');
});

it('refuses to move a group the scheduler has claimed', function (TaskGroupStatus $claimed): void {
    $group = backlog_group($this);
    Task::topLevel()->whereKey($group['id'])->update(['status' => $claimed]);

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'backlog'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.already_claimed');

    expect(Task::topLevel()->findOrFail($group['id'])->status)->toBe($claimed);
})->with([
    'reserved' => TaskGroupStatus::Reserved,
    'running' => TaskGroupStatus::Running,
    'settling' => TaskGroupStatus::Settling,
    'completed' => TaskGroupStatus::Completed,
]);

it('accepts only backlog and todo as a status', function (string $status): void {
    $group = backlog_group($this);

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => $status])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');
})->with(['queued', 'running', 'pending']);

it('reorders a subtask and keeps positions gapless', function (): void {
    $group = backlog_group($this);
    $three = $group['tasks'][2]['id'];

    $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$three}", ['position' => 1, 'title' => 'Three first'])
        ->assertOk()
        ->assertJsonPath('data.position', 1)
        ->assertJsonPath('data.title', 'Three first');

    expect(backlog_order($group['id']))->toBe(['Three first', 'One', 'Two'])
        ->and(Task::query()->where('parent_id', $group['id'])->orderBy('position')->pluck('position')->all())->toBe([1, 2, 3]);

    $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$three}", ['position' => 3])->assertOk();

    expect(backlog_order($group['id']))->toBe(['One', 'Two', 'Three first']);
});

it('rejects a position outside the subtask list', function (int $position): void {
    $group = backlog_group($this);

    $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$group['tasks'][0]['id']}", ['position' => $position])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(backlog_order($group['id']))->toBe(['One', 'Two', 'Three']);
})->with([0, 4]);

it('destroys a subtask and closes the gap', function (): void {
    $group = backlog_group($this);

    $this->deleteJson("/api/v1/task-groups/{$group['id']}/tasks/{$group['tasks'][1]['id']}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Two');

    expect(backlog_order($group['id']))->toBe(['One', 'Three'])
        ->and(Task::query()->where('parent_id', $group['id'])->orderBy('position')->pluck('position')->all())->toBe([1, 2]);
});

it('refuses subtask update and destroy outside backlog but still appends subtasks', function (): void {
    $group = backlog_group($this);
    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'todo'])->assertOk();
    $first = $group['tasks'][0]['id'];
    Task::query()->whereKey($first)->update(['status' => TaskStatus::Running->value]);

    $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$first}", ['title' => 'Changed'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.not_in_backlog');
    $this->deleteJson("/api/v1/task-groups/{$group['id']}/tasks/{$first}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.not_in_backlog');
    $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", ['title' => 'Four', 'brief' => 'Four brief.', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Four is done.']]])
        ->assertCreated()
        ->assertJsonPath('data.position', 4)
        ->assertJsonPath('data.status', 'todo');

    expect(backlog_order($group['id']))->toBe(['One', 'Two', 'Three', 'Four']);
});

it('allows a todo subtask of a running group to change its title, brief, and position among todo subtasks', function (): void {
    $group = backlog_group($this, ['Started', 'Second', 'Third']);
    Task::topLevel()->whereKey($group['id'])->update(['status' => TaskGroupStatus::Running->value]);
    Task::query()->whereKey($group['tasks'][0]['id'])->update(['status' => TaskStatus::Running->value]);

    $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$group['tasks'][1]['id']}", [
        'title' => 'Renamed second',
        'brief' => 'The corrected second brief.',
        'position' => 3,
    ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed second')
        ->assertJsonPath('data.brief', 'The corrected second brief.')
        ->assertJsonPath('data.position', 3);

    expect(backlog_order($group['id']))->toBe(['Started', 'Third', 'Renamed second'])
        ->and(Task::query()->where('parent_id', $group['id'])->orderBy('position')->pluck('position')->all())->toBe([1, 2, 3]);
});

it('refuses to move a todo subtask of a running group across a cancelled middle subtask', function (): void {
    $group = backlog_group($this, ['Started', 'Second', 'Cancelled', 'Fourth']);
    Task::topLevel()->whereKey($group['id'])->update(['status' => TaskGroupStatus::Running->value]);
    Task::query()->whereKey($group['tasks'][0]['id'])->update(['status' => TaskStatus::Running->value]);
    Task::query()->whereKey($group['tasks'][2]['id'])->update(['status' => TaskStatus::Cancelled->value, 'settled_at' => now()]);

    $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$group['tasks'][3]['id']}", ['position' => 2])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(backlog_order($group['id']))->toBe(['Started', 'Second', 'Cancelled', 'Fourth'])
        ->and(Task::query()->findOrFail($group['tasks'][2]['id'])->position)->toBe(3)
        ->and(Task::query()->findOrFail($group['tasks'][3]['id'])->position)->toBe(4);
});

it('refuses to move a todo subtask of a running group before a started subtask', function (): void {
    $group = backlog_group($this, ['Started', 'Second', 'Third']);
    Task::topLevel()->whereKey($group['id'])->update(['status' => TaskGroupStatus::Running->value]);
    Task::query()->whereKey($group['tasks'][0]['id'])->update(['status' => TaskStatus::Running->value]);

    $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$group['tasks'][1]['id']}", ['position' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(backlog_order($group['id']))->toBe(['Started', 'Second', 'Third']);
});

it('cancels a todo subtask of a running group without starting anything', function (): void {
    $group = backlog_group($this, ['Started', 'Todo']);
    Task::topLevel()->whereKey($group['id'])->update(['status' => TaskGroupStatus::Running->value]);
    Task::query()->whereKey($group['tasks'][0]['id'])->update(['status' => TaskStatus::Running->value]);

    $this->postJson("/api/v1/task-groups/{$group['id']}/tasks/{$group['tasks'][1]['id']}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $cancelled = Task::query()->findOrFail($group['tasks'][1]['id']);
    expect($cancelled->status)->toBe(TaskStatus::Cancelled)
        ->and($cancelled->settled_at)->not->toBeNull()
        ->and($cancelled->started_at)->toBeNull()
        ->and(Task::query()->findOrFail($group['tasks'][0]['id'])->status)->toBe(TaskStatus::Running)
        ->and(Task::topLevel()->findOrFail($group['id'])->status)->toBe(TaskGroupStatus::Running);
});

it('settles after cancelling the only todo subtask of a running group without assistance', function (): void {
    $notifier = Mockery::mock(CoderSettleNotifier::class);
    $notifier->shouldNotReceive('assistance');
    app()->instance(CoderSettleNotifier::class, $notifier);

    $group = backlog_group($this, ['Todo']);
    Task::topLevel()->whereKey($group['id'])->update(['status' => TaskGroupStatus::Running->value]);

    $this->postJson("/api/v1/task-groups/{$group['id']}/tasks/{$group['tasks'][0]['id']}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $cancelled = Task::query()->findOrFail($group['tasks'][0]['id']);
    $settled = Task::topLevel()->findOrFail($group['id']);
    expect($cancelled->status)->toBe(TaskStatus::Cancelled)
        ->and($cancelled->started_at)->toBeNull()
        ->and($cancelled->assistance_requested)->toBeFalse()
        ->and($cancelled->assistance_reason)->toBeNull()
        ->and($settled->status)->toBe(TaskGroupStatus::Settling)
        ->and($settled->assistance_requested)->toBeFalse()
        ->and($settled->assistance_reason)->toBeNull();
});

it('does not check merged pull request assistance when cancelling todo in a returned settled group', function (): void {
    $watcher = Mockery::mock(TaskPullRequestWatcher::class);
    $watcher->shouldNotReceive('health');
    $watcher->shouldNotReceive('status');
    app()->instance(TaskPullRequestWatcher::class, $watcher);
    $notifier = Mockery::mock(CoderSettleNotifier::class);
    $notifier->shouldNotReceive('assistance');
    app()->instance(CoderSettleNotifier::class, $notifier);

    $group = backlog_group($this, ['Todo']);
    $task = Task::query()->findOrFail($group['tasks'][0]['id']);
    Task::topLevel()->whereKey($group['id'])->update([
        'status' => TaskGroupStatus::Settling->value,
        'settled_at' => now()->subMinute(),
        'pr_url' => 'https://github.com/acme/orbit/pull/42',
    ]);
    TaskComment::query()->create([
        'task_group_id' => $group['id'],
        'task_id' => $task->id,
        'type' => TaskCommentType::Approved,
        'body' => 'Approved the latest subtask.',
        'author' => 'reviewer',
        'commit_sha' => str_repeat('a', 40),
        'posted_at' => now()->subMinutes(2),
    ]);

    $this->postJson("/api/v1/task-groups/{$group['id']}/tasks/{$task->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $cancelled = $task->fresh();
    $settled = Task::topLevel()->findOrFail($group['id']);
    expect($cancelled?->assistance_requested)->toBeFalse()
        ->and($cancelled?->assistance_reason)->toBeNull()
        ->and($settled->status)->toBe(TaskGroupStatus::Settling)
        ->and($settled->assistance_requested)->toBeFalse()
        ->and($settled->assistance_reason)->toBeNull();
});

it('keeps refusing a brief change on a started todo subtask of a running group', function (): void {
    $group = backlog_group($this, ['Started']);
    Task::topLevel()->whereKey($group['id'])->update(['status' => TaskGroupStatus::Running->value]);
    Task::query()->whereKey($group['tasks'][0]['id'])->update(['status' => TaskStatus::Running->value]);

    $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$group['tasks'][0]['id']}", ['brief' => 'Changed after start.'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.not_in_backlog');

    expect(Task::query()->findOrFail($group['tasks'][0]['id'])->brief)->toBe('Started brief.');
});

it('refuses subtask creation in completed and cancelled groups', function (TaskGroupStatus $status): void {
    $group = backlog_group($this, ['One']);
    Task::topLevel()->whereKey($group['id'])->update(['status' => $status->value]);

    $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", [
        'title' => 'Closed work',
        'brief' => 'Cannot be added.',
        'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done.']],
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.group_closed');

    expect(Task::query()->where('parent_id', $group['id'])->count())->toBe(1);
})->with([TaskGroupStatus::Completed, TaskGroupStatus::Cancelled]);

it('returns 404 for a subtask of another group', function (): void {
    $group = backlog_group($this);
    $other = backlog_group($this, ['Other']);

    $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$other['tasks'][0]['id']}", ['title' => 'Nope'])->assertNotFound();
    $this->deleteJson("/api/v1/task-groups/{$group['id']}/tasks/{$other['tasks'][0]['id']}")->assertNotFound();

    expect(Task::query()->findOrFail($other['tasks'][0]['id'])->title)->toBe('Other');
});

it('cancels a backlog group', function (): void {
    $group = backlog_group($this);

    $this->postJson("/api/v1/task-groups/{$group['id']}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('filters the list by backlog and rejects the old queued status', function (): void {
    $group = backlog_group($this);

    $this->getJson('/api/v1/task-groups?status=backlog')
        ->assertOk()
        ->assertJsonPath('data.0.id', $group['id']);
    $this->getJson('/api/v1/task-groups?status=todo')
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/task-groups?status=queued')
        ->assertUnprocessable();
});

it('cancels the unfinished subtasks with their group and keeps finished ones', function (): void {
    $group = backlog_group($this);
    Task::topLevel()->whereKey($group['id'])->update(['status' => TaskGroupStatus::Running]);
    Task::query()->whereKey($group['tasks'][0]['id'])->update(['status' => TaskStatus::Completed]);
    Task::query()->whereKey($group['tasks'][1]['id'])->update(['status' => TaskStatus::Running]);

    $cancelled = $this->postJson("/api/v1/task-groups/{$group['id']}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->json('data.tasks');

    expect(array_column($cancelled, 'status'))->toBe(['completed', 'cancelled', 'cancelled'])
        ->and(Task::query()->findOrFail($group['tasks'][1]['id'])->settled_at)->not->toBeNull()
        ->and(Task::query()->findOrFail($group['tasks'][0]['id'])->settled_at)->toBeNull();
});

it('shows a requested assistance and its reason on the group and the subtask', function (): void {
    $group = backlog_group($this, ['One']);
    Task::topLevel()->whereKey($group['id'])->update(['assistance_requested' => true, 'assistance_reason' => 'composer check is blocked.']);
    Task::query()->whereKey($group['tasks'][0]['id'])->update(['assistance_requested' => true, 'assistance_reason' => 'composer check is blocked.']);

    $this->getJson("/api/v1/task-groups/{$group['id']}")
        ->assertOk()
        ->assertJsonPath('data.assistance_requested', true)
        ->assertJsonPath('data.assistance_reason', 'composer check is blocked.')
        ->assertJsonPath('data.tasks.0.assistance_requested', true)
        ->assertJsonPath('data.tasks.0.assistance_reason', 'composer check is blocked.');

    expect(backlog_group($this, ['Two']))->toMatchArray(['assistance_requested' => false, 'assistance_reason' => null]);
});

it('clears a requested assistance when the group is cancelled', function (): void {
    $group = backlog_group($this, ['One']);
    Task::topLevel()->whereKey($group['id'])->update(['status' => TaskGroupStatus::Running, 'assistance_requested' => true, 'assistance_reason' => 'composer check is blocked.']);
    Task::query()->whereKey($group['tasks'][0]['id'])->update(['status' => TaskStatus::Running, 'assistance_requested' => true, 'assistance_reason' => 'composer check is blocked.']);

    $this->postJson("/api/v1/task-groups/{$group['id']}/cancel")
        ->assertOk()
        ->assertJsonPath('data.assistance_requested', false)
        ->assertJsonPath('data.assistance_reason', 'composer check is blocked.')
        ->assertJsonPath('data.tasks.0.assistance_requested', false);
});

describe('subtask deliverables', function (): void {
    it('stores typed deliverables on a subtask and returns the fields of each type', function (): void {
        $group = backlog_group($this, []);

        $created = $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", ['title' => 'Export', 'brief' => 'Add the export.', 'deliverables' => [
            ['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export', 'path' => 'docs/reference/tasks.md', 'change' => 'modified'],
            ['id' => 'export-test', 'type' => 'command', 'description' => 'Test the export', 'command' => 'vendor/bin/pest tests/Feature/ExportTest.php', 'directory' => 'apps/gateway'],
            ['id' => 'web-tests', 'type' => 'command', 'description' => 'The web tests pass', 'command' => 'bun test'],
            ['id' => 'error-copy', 'type' => 'review', 'description' => 'Errors name the subtask'],
        ]])->assertCreated()->json('data');

        expect($this->getJson("/api/v1/task-groups/{$group['id']}")->assertOk()->json('data.tasks.0.deliverables'))->toBe([
            ['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export', 'path' => 'docs/reference/tasks.md', 'change' => 'modified'],
            ['id' => 'export-test', 'type' => 'command', 'description' => 'Test the export', 'command' => 'vendor/bin/pest tests/Feature/ExportTest.php', 'directory' => 'apps/gateway'],
            ['id' => 'web-tests', 'type' => 'command', 'description' => 'The web tests pass', 'command' => 'bun test', 'directory' => '.'],
            ['id' => 'error-copy', 'type' => 'review', 'description' => 'Errors name the subtask'],
        ])->and($created['deliverables'])->toHaveCount(4);
    });

    it('refuses a deliverable that does not fit its type', function (array $deliverable, string $field, string $message): void {
        $group = backlog_group($this, []);

        $details = $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", ['title' => 'Export', 'brief' => 'Add it.', 'deliverables' => [$deliverable]])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->json('error.details');

        expect($details[$field][0] ?? null)->toBe($message);
    })->with([
        'an unknown type' => [['id' => 'docs', 'type' => 'doc', 'description' => 'Docs'], 'deliverables.0.type', 'The selected deliverables.0.type is invalid.'],
        'a missing id' => [['type' => 'review', 'description' => 'Docs'], 'deliverables.0.id', 'The deliverables.0.id field is required.'],
        'an id that is not a slug' => [['id' => 'Docs page', 'type' => 'review', 'description' => 'Docs'], 'deliverables.0.id', 'The deliverables.0.id field format is invalid.'],
        'a missing description' => [['id' => 'docs', 'type' => 'review'], 'deliverables.0.description', 'The deliverables.0.description field is required.'],
        'a file without a path' => [['id' => 'docs', 'type' => 'file', 'description' => 'Docs', 'change' => 'any'], 'deliverables.0.path', 'The deliverables.0.path field is required when deliverables.0.type is file.'],
        'a file without a change' => [['id' => 'docs', 'type' => 'file', 'description' => 'Docs', 'path' => 'docs/a.md'], 'deliverables.0.change', 'The deliverables.0.change field is required when deliverables.0.type is file.'],
        'a file with an unknown change' => [['id' => 'docs', 'type' => 'file', 'description' => 'Docs', 'path' => 'docs/a.md', 'change' => 'deleted'], 'deliverables.0.change', 'The selected deliverables.0.change is invalid.'],
        'a path outside the workspace' => [['id' => 'docs', 'type' => 'file', 'description' => 'Docs', 'path' => '../secrets.md', 'change' => 'any'], 'deliverables.0.path', 'The deliverables.0.path field format is invalid.'],
        'an absolute path' => [['id' => 'docs', 'type' => 'file', 'description' => 'Docs', 'path' => '/etc/passwd', 'change' => 'any'], 'deliverables.0.path', 'The deliverables.0.path field format is invalid.'],
        'the removed test type' => [['id' => 'export-test', 'type' => 'test', 'description' => 'Test'], 'deliverables.0.type', 'The selected deliverables.0.type is invalid.'],
        'a command without a command' => [['id' => 'web', 'type' => 'command', 'description' => 'Web tests'], 'deliverables.0.command', 'The deliverables.0.command field is required when deliverables.0.type is command.'],
        'a command directory outside the workspace' => [['id' => 'web', 'type' => 'command', 'description' => 'Web tests', 'command' => 'bun test', 'directory' => 'apps/../../etc'], 'deliverables.0.directory', 'The deliverables.0.directory field format is invalid.'],
        'a field of another type' => [['id' => 'docs', 'type' => 'review', 'description' => 'Docs', 'path' => 'docs/a.md'], 'deliverables.0.path', 'The deliverables.0.path field is prohibited unless deliverables.0.type is in file.'],
        'an unknown field' => [['id' => 'docs', 'type' => 'review', 'description' => 'Docs', 'owner' => 'nick'], 'deliverables.0', 'The deliverables.0 field must be an array.'],
    ]);

    it('refuses an id twice within one subtask but allows it across subtasks', function (): void {
        $review = ['id' => 'done', 'type' => 'review', 'description' => 'Done.'];

        $this->postJson('/api/v1/task-groups', ['project_id' => $this->appRecord->id, 'title' => 'Twice', 'brief' => 'Twice.', 'tasks' => [
            ['title' => 'One', 'brief' => 'One.', 'deliverables' => [$review, $review]],
        ]])->assertUnprocessable()->assertJsonPath('error.details', ['tasks.0.deliverables' => ['Each deliverable id must be unique within the subtask. Repeated: done.']]);

        $this->postJson('/api/v1/task-groups', ['project_id' => $this->appRecord->id, 'title' => 'Across', 'brief' => 'Across.', 'tasks' => [
            ['title' => 'One', 'brief' => 'One.', 'deliverables' => [$review]],
            ['title' => 'Two', 'brief' => 'Two.', 'deliverables' => [$review]],
        ]])->assertCreated();
    });

    it('refuses more than five deliverables on a subtask', function (): void {
        $group = backlog_group($this, []);
        $deliverables = array_map(static fn (int $index): array => ['id' => "item-{$index}", 'type' => 'review', 'description' => 'Item.'], range(1, 6));

        $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", ['title' => 'Many', 'brief' => 'Many.', 'deliverables' => $deliverables])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.deliverables', ['The deliverables field must not have more than 5 items.']);
    });

    it('refuses to move a group to todo while a subtask has no deliverables', function (): void {
        $group = backlog_group($this, ['One']);
        $bare = $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", ['title' => 'Bare', 'brief' => 'No deliverables.'])->assertCreated()->json('data');

        $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'todo'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'tasks.subtask_deliverables_missing')
            ->assertJsonPath('error.details.subtasks', "#{$bare['id']} \"Bare\"");

        expect(Task::topLevel()->findOrFail($group['id'])->status)->toBe(TaskGroupStatus::Backlog)
            ->and($this->provisioning->calls)->toBe(0);
    });

    it('refuses to create a group in todo while a subtask has no deliverables', function (): void {
        $this->postJson('/api/v1/task-groups', ['project_id' => $this->appRecord->id, 'title' => 'Ready', 'brief' => 'Ready.', 'status' => 'todo', 'tasks' => [
            ['title' => 'One', 'brief' => 'One.', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done.']]],
            ['title' => 'Two', 'brief' => 'Two.'],
        ]])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'tasks.subtask_deliverables_missing')
            ->assertJsonPath('error.details.subtasks', 'position 2 "Two"');

        expect(Task::topLevel()->count())->toBe(0);
    });

    it('needs deliverables for a subtask added after the group left backlog', function (): void {
        $group = backlog_group($this, ['One'], ['status' => 'todo']);

        $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", ['title' => 'Two', 'brief' => 'Two.'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'tasks.subtask_deliverables_missing');
        $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", ['title' => 'Two', 'brief' => 'Two.', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done.']]])
            ->assertCreated();
    });

    it('replaces the deliverables of a todo subtask after its group left backlog, but not of a started one', function (): void {
        $group = backlog_group($this, ['One', 'Two'], ['status' => 'todo']);
        Task::topLevel()->whereKey($group['id'])->update(['status' => TaskGroupStatus::Running->value]);
        [$running, $todo] = [$group['tasks'][0]['id'], $group['tasks'][1]['id']];
        Task::query()->whereKey($running)->update(['status' => TaskStatus::Running->value]);
        $docs = [['id' => 'reference-page', 'type' => 'file', 'description' => 'Docs', 'path' => 'docs/reference/tasks.md', 'change' => 'modified']];

        $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$todo}", ['deliverables' => $docs])
            ->assertOk()
            ->assertJsonPath('data.deliverables', $docs);
        $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$running}", ['deliverables' => $docs])
            ->assertConflict()
            ->assertJsonPath('error.code', 'tasks.deliverables_locked')
            ->assertJsonPath('error.message', 'Deliverables change only while the group is in backlog or the subtask is todo.');
        $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$todo}", ['deliverables' => []])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'tasks.subtask_deliverables_missing');
        $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$todo}", ['deliverables' => $docs, 'title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed');

        expect(Task::query()->findOrFail($todo)->title)->toBe('Renamed')
            ->and(Task::query()->findOrFail($running)->deliverables)->toBe([
                ['id' => 'done', 'type' => 'review', 'description' => 'One is done.'],
            ])
            ->and(Task::query()->findOrFail($running)->status)->toBe(TaskStatus::Running);
    });

    it('accepts generic command deliverables with a base-run path list and file globs', function (): void {
        $group = backlog_group($this, []);
        $deliverables = [
            ['id' => 'reference-page', 'type' => 'file', 'description' => 'Docs across directories', 'path' => 'docs/**/*.md', 'change' => 'any'],
            ['id' => 'pantry-sync', 'type' => 'file', 'description' => 'Pantry sync types', 'path' => 'app/{Data,Enums}/PantrySync/**/*.php', 'change' => 'any'],
            ['id' => 'integration', 'type' => 'file', 'description' => 'Integration screens', 'path' => 'resources/js/**/*Pantry*Integration*.{php,tsx}', 'change' => 'any'],
            ['id' => 'tasks-domain', 'type' => 'file', 'description' => 'Task domain', 'path' => 'apps/gateway/app/Domain/Tasks/**/*.{php}', 'change' => 'any'],
            ['id' => 'layout-repro', 'type' => 'command', 'description' => 'Reproduce the layout regression', 'command' => 'vendor/bin/pest tests/Feature/HomeScreenTest.php', 'directory' => 'apps/gateway', 'fails_on_base' => true, 'paths' => ['apps/gateway/tests/Feature/HomeScreenTest.php']],
        ];
        $created = $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", [
            'title' => 'Layout', 'brief' => 'Fix the layout.', 'deliverables' => $deliverables,
        ])->assertCreated()->json('data');

        expect($created['deliverables'])->toBe($deliverables);

        $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$created['id']}", ['deliverables' => $deliverables])
            ->assertOk()->assertJsonPath('data.deliverables', $deliverables);
        $this->postJson('/api/v1/task-groups', [
            'project_id' => $this->appRecord->id,
            'title' => 'Layout group',
            'brief' => 'Fix the layout.',
            'tasks' => [['title' => 'Layout', 'brief' => 'Fix the layout.', 'deliverables' => $deliverables]],
        ])->assertCreated()->assertJsonPath('data.tasks.0.deliverables', $deliverables);
    });

    it('stores fails_on_base on a command deliverable and rejects invalid values', function (mixed $value, string $message): void {
        $group = backlog_group($this, []);
        $repro = ['id' => 'layout-repro', 'type' => 'command', 'description' => 'The layout fails before the fix', 'command' => 'vendor/bin/pest tests/Feature/HomeScreenTest.php', 'directory' => 'apps/gateway', 'fails_on_base' => $value, 'paths' => ['apps/gateway/tests/Feature/HomeScreenTest.php']];
        $groups = Task::topLevel()->count();

        if ($value === true || $value === false) {
            $created = $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", [
                'title' => 'Layout', 'brief' => 'Fix the layout.', 'deliverables' => [$repro],
            ])->assertCreated()->json('data');

            expect($created['deliverables'])->toBe([$repro])
                ->and($this->getJson("/api/v1/task-groups/{$group['id']}")->assertOk()->json('data.tasks.0.deliverables'))->toBe([$repro])
                ->and(Task::query()->findOrFail($created['id'])->deliverables)->toBe([$repro]);

            $updated = $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$created['id']}", [
                'deliverables' => [[...$repro, 'fails_on_base' => $value === false]],
            ])->assertOk()->json('data');

            expect($updated['deliverables'][0]['fails_on_base'])->toBe($value === false);

            $this->postJson('/api/v1/task-groups', [
                'project_id' => $this->appRecord->id,
                'title' => 'Layout group',
                'brief' => 'Fix the layout.',
                'tasks' => [['title' => 'Layout', 'brief' => 'Fix the layout.', 'deliverables' => [$repro]]],
            ])->assertCreated()->assertJsonPath('data.tasks.0.deliverables.0.fails_on_base', $value);

            return;
        }

        $created = $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", [
            'title' => 'Layout', 'brief' => 'Fix the layout.', 'deliverables' => [$repro],
        ]);

        expect($created->assertUnprocessable()->json('error.code'))->toBe('validation.failed')
            ->and($created->json('error.details')['deliverables.0.fails_on_base'][0] ?? null)->toBe($message)
            ->and(Task::query()->where('parent_id', $group['id'])->count())->toBe(0);

        $subtask = $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", [
            'title' => 'Layout', 'brief' => 'Fix the layout.',
            'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done.']],
        ])->assertCreated()->json('data');
        $updated = $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$subtask['id']}", [
            'deliverables' => [$repro],
        ]);

        expect($updated->assertUnprocessable()->json('error.code'))->toBe('validation.failed')
            ->and($updated->json('error.details')['deliverables.0.fails_on_base'][0] ?? null)->toBe($message)
            ->and(Task::query()->findOrFail($subtask['id'])->deliverables)->toBe([
                ['id' => 'done', 'type' => 'review', 'description' => 'Done.'],
            ]);

        $groupCreated = $this->postJson('/api/v1/task-groups', [
            'project_id' => $this->appRecord->id,
            'title' => 'Layout group',
            'brief' => 'Refuse the field.',
            'tasks' => [['title' => 'Layout', 'brief' => 'Fix the layout.', 'deliverables' => [$repro]]],
        ]);

        expect($groupCreated->assertUnprocessable()->json('error.code'))->toBe('validation.failed')
            ->and($groupCreated->json('error.details')['tasks.0.deliverables.0.fails_on_base'][0] ?? null)->toBe($message)
            ->and(Task::topLevel()->count())->toBe($groups);
    })->with([
        'true' => [true, ''],
        'false' => [false, ''],
        'the string true' => ['true', 'The fails_on_base value for deliverable layout-repro must be true or false.'],
        'the string false' => ['false', 'The fails_on_base value for deliverable layout-repro must be true or false.'],
        'one' => [1, 'The fails_on_base value for deliverable layout-repro must be true or false.'],
        'zero' => [0, 'The fails_on_base value for deliverable layout-repro must be true or false.'],
        'null' => [null, 'The fails_on_base value for deliverable layout-repro must be true or false.'],
    ]);

    it('enforces base paths and command-only paths across create, update, and group-create requests', function (array $deliverable, string $message): void {
        $group = backlog_group($this, []);
        $existing = $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", [
            'title' => 'Existing', 'brief' => 'Existing task.',
            'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done.']],
        ])->assertCreated()->json('data');

        $created = $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", [
            'title' => 'Invalid', 'brief' => 'Invalid task.', 'deliverables' => [$deliverable],
        ]);
        $updated = $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$existing['id']}", [
            'deliverables' => [$deliverable],
        ]);
        $groupCreated = $this->postJson('/api/v1/task-groups', [
            'project_id' => $this->appRecord->id,
            'title' => 'Invalid group',
            'brief' => 'Invalid task.',
            'tasks' => [['title' => 'Invalid', 'brief' => 'Invalid task.', 'deliverables' => [$deliverable]]],
        ]);

        expect($created->assertUnprocessable()->json('error.details')['deliverables.0.paths'][0] ?? null)->toBe($message)
            ->and($updated->assertUnprocessable()->json('error.details')['deliverables.0.paths'][0] ?? null)->toBe($message)
            ->and($groupCreated->assertUnprocessable()->json('error.details')['tasks.0.deliverables.0.paths'][0] ?? null)->toBe($message);
    })->with([
        'base command needs paths' => [
            ['id' => 'base-check', 'type' => 'command', 'description' => 'Reproduce the issue', 'command' => 'phpunit tests/ExampleTest.php', 'fails_on_base' => true],
            'The paths value for deliverable base-check must contain at least one path when fails_on_base is true.',
        ],
        'review cannot have empty paths' => [
            ['id' => 'review-check', 'type' => 'review', 'description' => 'Review the result', 'paths' => []],
            'The paths field is only allowed on a command deliverable (deliverable review-check).',
        ],
    ]);

    it('returns 422 validation.failed when fails_on_base is set on a deliverable that is not a command', function (array $deliverable): void {
        $group = backlog_group($this, []);
        $message = 'The fails_on_base field is only allowed on a command deliverable (deliverable docs).';
        $groups = Task::topLevel()->count();

        $created = $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", [
            'title' => 'Docs', 'brief' => 'Write the docs.', 'deliverables' => [$deliverable],
        ]);

        expect($created->assertUnprocessable()->json('error.code'))->toBe('validation.failed')
            ->and($created->json('error.details')['deliverables.0.fails_on_base'][0] ?? null)->toBe($message)
            ->and(Task::query()->where('parent_id', $group['id'])->count())->toBe(0);

        $subtask = $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", [
            'title' => 'Docs', 'brief' => 'Write the docs.',
            'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done.']],
        ])->assertCreated()->json('data');
        $updated = $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$subtask['id']}", [
            'deliverables' => [$deliverable],
        ]);

        expect($updated->assertUnprocessable()->json('error.details')['deliverables.0.fails_on_base'][0] ?? null)->toBe($message)
            ->and(Task::query()->findOrFail($subtask['id'])->deliverables)->toBe([
                ['id' => 'done', 'type' => 'review', 'description' => 'Done.'],
            ]);

        $groupCreated = $this->postJson('/api/v1/task-groups', [
            'project_id' => $this->appRecord->id,
            'title' => 'Docs group',
            'brief' => 'Refuse the field.',
            'tasks' => [['title' => 'Docs', 'brief' => 'Write the docs.', 'deliverables' => [$deliverable]]],
        ]);

        expect($groupCreated->assertUnprocessable()->json('error.details')['tasks.0.deliverables.0.fails_on_base'][0] ?? null)->toBe($message)
            ->and(Task::topLevel()->count())->toBe($groups);
    })->with([
        'a file' => [['id' => 'docs', 'type' => 'file', 'description' => 'Docs', 'path' => 'docs/a.md', 'change' => 'any', 'fails_on_base' => true]],
        'a review' => [['id' => 'docs', 'type' => 'review', 'description' => 'Docs', 'fails_on_base' => true]],
    ]);
});

/** @param list<array<string, mixed>> $deliverables */
function stored_test_deliverables(array $deliverables): array
{
    return array_map(static function (array $deliverable): array {
        return $deliverable;
    }, $deliverables);
}
