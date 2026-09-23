<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;

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
    $this->appRecord = OrbitApp::query()->create([
        'name' => 'Backlog demo',
        'slug' => 'backlog-demo',
        'repository_url' => 'git@example.test:backlog-demo.git',
        'default_branch' => 'main',
    ]);
    $this->provisioning = new class implements InstanceProvisioning
    {
        public int $calls = 0;

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
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
        'app_id' => $test->appRecord->id,
        'title' => 'Backlog feature',
        'brief' => 'Prepare before running.',
        'tasks' => array_map(static fn (string $title): array => ['title' => $title, 'brief' => "{$title} brief."], $subtasks),
        ...$extra,
    ])->assertCreated()->json('data');
}

/** @return list<string> */
function backlog_order(int $groupId): array
{
    /** @var list<string> */
    return Task::query()->where('task_group_id', $groupId)->orderBy('position')->pluck('title')->all();
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
        'app_id' => $this->appRecord->id,
        'title' => 'Empty',
        'brief' => 'No subtasks.',
        'status' => 'todo',
    ])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.no_subtasks');

    expect(TaskGroup::query()->count())->toBe(0);

    $group = backlog_group($this, []);

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'todo'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'tasks.no_subtasks');

    expect(TaskGroup::query()->findOrFail($group['id'])->status)->toBe(TaskGroupStatus::Backlog)
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

    expect(TaskGroup::query()->findOrFail($group['id'])->title)->toBe('Renamed');
});

it('refuses to move a group the scheduler has claimed', function (TaskGroupStatus $claimed): void {
    $group = backlog_group($this);
    TaskGroup::query()->whereKey($group['id'])->update(['status' => $claimed]);

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'backlog'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.already_claimed');

    expect(TaskGroup::query()->findOrFail($group['id'])->status)->toBe($claimed);
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
        ->and(Task::query()->where('task_group_id', $group['id'])->orderBy('position')->pluck('position')->all())->toBe([1, 2, 3]);

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
        ->and(Task::query()->where('task_group_id', $group['id'])->orderBy('position')->pluck('position')->all())->toBe([1, 2]);
});

it('refuses subtask update and destroy outside backlog but still appends subtasks', function (): void {
    $group = backlog_group($this);
    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'todo'])->assertOk();
    $first = $group['tasks'][0]['id'];

    $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$first}", ['title' => 'Changed'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.not_in_backlog');
    $this->deleteJson("/api/v1/task-groups/{$group['id']}/tasks/{$first}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.not_in_backlog');
    $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", ['title' => 'Four', 'brief' => 'Four brief.'])
        ->assertCreated()
        ->assertJsonPath('data.position', 4)
        ->assertJsonPath('data.status', 'todo');

    expect(backlog_order($group['id']))->toBe(['One', 'Two', 'Three', 'Four']);
});

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
    TaskGroup::query()->whereKey($group['id'])->update(['status' => TaskGroupStatus::Running]);
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
    TaskGroup::query()->whereKey($group['id'])->update(['assistance_requested' => true, 'assistance_reason' => 'composer check is blocked.']);
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
    TaskGroup::query()->whereKey($group['id'])->update(['status' => TaskGroupStatus::Running, 'assistance_requested' => true, 'assistance_reason' => 'composer check is blocked.']);
    Task::query()->whereKey($group['tasks'][0]['id'])->update(['status' => TaskStatus::Running, 'assistance_requested' => true, 'assistance_reason' => 'composer check is blocked.']);

    $this->postJson("/api/v1/task-groups/{$group['id']}/cancel")
        ->assertOk()
        ->assertJsonPath('data.assistance_requested', false)
        ->assertJsonPath('data.assistance_reason', 'composer check is blocked.')
        ->assertJsonPath('data.tasks.0.assistance_requested', false);
});
