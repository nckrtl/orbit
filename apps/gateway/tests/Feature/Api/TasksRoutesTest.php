<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Api\TaskGroupsController;
use App\Http\Controllers\Api\TasksController;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskGroup;
use Illuminate\Routing\Route;
use Tests\Support\FakeTaskCheckRunner;

function tasks_gateway(): Node
{
    $gateway = Node::query()->create([
        'name' => 'tasks-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'wireguard_ip' => '10.44.0.80',
    ]);

    test()->markAsGateway($gateway);
    test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);

    return $gateway;
}

function tasks_app(string $slug = 'commander-demo'): OrbitApp
{
    return OrbitApp::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
        'default_branch' => 'main',
    ]);
}

function enable_tasks(): void
{
    test()->postJson('/api/v1/tasks/enable')->assertOk()->assertJsonPath('data.enabled', true);
}

it('exposes the tasks routes with stable methods', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn (Route $route): bool => str_starts_with((string) $route->getName(), 'tasks:'))
        ->mapWithKeys(static fn (Route $route): array => [
            $route->getName() => [$route->uri(), $route->methods()],
        ])
        ->all();

    expect($routes)->toBe([
        'tasks:agents' => ['api/v1/task-groups/{group}/agents', ['GET', 'HEAD']],
        'tasks:agent-stream' => ['api/v1/task-groups/{group}/agents/{session}/stream', ['GET', 'HEAD']],
        'tasks:enable' => ['api/v1/tasks/enable', ['POST']],
        'tasks:disable' => ['api/v1/tasks/disable', ['POST']],
        'tasks:status' => ['api/v1/tasks/status', ['GET', 'HEAD']],
        'tasks:list' => ['api/v1/task-groups', ['GET', 'HEAD']],
        'tasks:create' => ['api/v1/task-groups', ['POST']],
        'tasks:show' => ['api/v1/task-groups/{group}', ['GET', 'HEAD']],
        'tasks:update' => ['api/v1/task-groups/{group}', ['PATCH']],
        'tasks:subtask:create' => ['api/v1/task-groups/{group}/tasks', ['POST']],
        'tasks:subtask:update' => ['api/v1/task-groups/{group}/tasks/{task}', ['PATCH']],
        'tasks:subtask:destroy' => ['api/v1/task-groups/{group}/tasks/{task}', ['DELETE']],
        'tasks:subtask:cancel' => ['api/v1/task-groups/{group}/tasks/{task}/cancel', ['POST']],
        'tasks:check:cancel' => ['api/v1/task-groups/{group}/tasks/{task}/check/cancel', ['POST']],
        'tasks:comment:create' => ['api/v1/task-groups/{group}/tasks/{task}/comments', ['POST']],
        'tasks:comment:list' => ['api/v1/task-groups/{group}/tasks/{task}/comments', ['GET', 'HEAD']],
        'tasks:cancel' => ['api/v1/task-groups/{group}/cancel', ['POST']],
        'tasks:complete' => ['api/v1/task-groups/{group}/complete', ['POST']],
    ]);
});

it('creates and reads operator task comments', function (): void {
    tasks_gateway();
    enable_tasks();
    $app = tasks_app('comments');
    $group = $this->postJson('/api/v1/task-groups', [
        'app_id' => $app->id,
        'title' => 'Comments',
        'brief' => 'Record comments.',
        'tasks' => [['title' => 'Comment task', 'brief' => 'A task.']],
    ])->assertCreated()->json('data');

    $taskId = $group['tasks'][0]['id'];
    foreach (['assistance_requested', 'resolution'] as $type) {
        $this->postJson("/api/v1/task-groups/{$group['id']}/tasks/{$taskId}/comments", [
            'type' => $type,
            'body' => "Body for {$type}.",
            'author' => 'operator@example.test',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', $type)
            ->assertJsonPath('data.body', "Body for {$type}.")
            ->assertJsonPath('data.author', 'operator@example.test')
            ->assertJsonPath('data.task_id', $taskId)
            ->assertJsonPath('data.task_group_id', $group['id'])
            ->assertJsonPath('data.posted_at', fn (mixed $value): bool => is_string($value));
    }

    $this->getJson("/api/v1/task-groups/{$group['id']}/tasks/{$taskId}/comments")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'resolution');
});

it('refuses turn outcomes, which agents report with the run script', function (string $type): void {
    tasks_gateway();
    enable_tasks();
    $app = tasks_app('invalid-comments');
    $group = $this->postJson('/api/v1/task-groups', [
        'app_id' => $app->id, 'title' => 'Comments', 'brief' => 'Record comments.',
        'tasks' => [['title' => 'Comment task', 'brief' => 'A task.']],
    ])->assertCreated()->json('data');
    $taskId = $group['tasks'][0]['id'];

    $this->postJson("/api/v1/task-groups/{$group['id']}/tasks/{$taskId}/comments", [
        'type' => $type, 'body' => 'Nope', 'author' => 'tester',
    ])->assertStatus(422);
})->with(['ready_for_review', 'changes_requested', 'approved', 'blocked', 'unknown']);

it('declares Gateway access for the extension and group lifecycle and group-owning access for plan changes', function (): void {
    expect(new ReflectionClass(TasksController::class)->getAttributes(RequiresNodeAccess::class)[0]->newInstance()->servingNode)
        ->toBe(ServingNode::Gateway)
        ->and(new ReflectionMethod(TaskGroupsController::class, 'store')->getAttributes(RequiresNodeAccess::class)[0]->newInstance()->servingNode)
        ->toBe(ServingNode::Gateway)
        ->and(new ReflectionMethod(TaskGroupsController::class, 'update')->getAttributes(RequiresNodeAccess::class)[0]->newInstance()->servingNode)
        ->toBe(ServingNode::TaskGroupOwning)
        ->and(new ReflectionMethod(TaskGroupsController::class, 'createTask')->getAttributes(RequiresNodeAccess::class)[0]->newInstance()->servingNode)
        ->toBe(ServingNode::TaskGroupOwning)
        ->and(new ReflectionMethod(TaskGroupsController::class, 'updateTask')->getAttributes(RequiresNodeAccess::class)[0]->newInstance()->servingNode)
        ->toBe(ServingNode::TaskGroupOwning)
        ->and(new ReflectionMethod(TaskGroupsController::class, 'destroyTask')->getAttributes(RequiresNodeAccess::class)[0]->newInstance()->servingNode)
        ->toBe(ServingNode::TaskGroupOwning)
        ->and(new ReflectionMethod(TaskGroupsController::class, 'complete')->getAttributes(RequiresNodeAccess::class)[0]->newInstance()->servingNode)
        ->toBe(ServingNode::Gateway)
        ->and(new ReflectionMethod(TaskGroupsController::class, 'cancel')->getAttributes(RequiresNodeAccess::class)[0]->newInstance()->servingNode)
        ->toBe(ServingNode::Gateway);
});

it('declares collection access for list and show', function (): void {
    expect(new ReflectionMethod(TaskGroupsController::class, 'index')->getAttributes(RequiresNodeAccess::class)[0]->newInstance()->servingNode)
        ->toBe(ServingNode::Collection)
        ->and(new ReflectionMethod(TaskGroupsController::class, 'show')->getAttributes(RequiresNodeAccess::class)[0]->newInstance()->servingNode)
        ->toBe(ServingNode::Collection);
});

it('enables and disables the extension through empty JSON objects', function (): void {
    tasks_gateway();

    $this->getJson('/api/v1/tasks/status')
        ->assertOk()
        ->assertJsonPath('data.enabled', false);

    $this->postJson('/api/v1/tasks/enable')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonStructure(['meta' => ['request_id']]);

    expect(app(TaskExtensionState::class)->enabled())->toBeTrue();

    $this->postJson('/api/v1/tasks/disable')
        ->assertOk()
        ->assertJsonPath('data.enabled', false);

    expect(app(TaskExtensionState::class)->enabled())->toBeFalse();
});

it('accepts notify_on_settle as the Commander alias for notify_coder', function (): void {
    tasks_gateway();
    enable_tasks();
    $app = tasks_app('notify-alias');

    $this->postJson('/api/v1/task-groups', [
        'app_id' => $app->id,
        'title' => 'Notify alias',
        'brief' => 'Commander callers send notify_on_settle.',
        'notify_on_settle' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('data.notify_coder', true);
});

it('returns 409 tasks.disabled for create list and show while the extension is off', function (): void {
    tasks_gateway();
    $app = tasks_app();

    $this->postJson('/api/v1/task-groups', [
        'app_id' => $app->id,
        'title' => 'Ship absorb',
        'brief' => 'Deliver the first slice. Accept when MCP create works.',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'tasks.disabled')
        ->assertJsonPath('error.message', 'The tasks extension is disabled.');

    $this->getJson('/api/v1/task-groups')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'tasks.disabled');

    expect(TaskGroup::query()->count())->toBe(0);
});

it('still returns the created group, and fails it when the first implementer cannot start after the baseline', function (): void {
    tasks_gateway();
    enable_tasks();
    $app = tasks_app('spawn-failure');
    $node = Node::query()->create([
        'name' => 'spawn-failure-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.81',
        'wireguard_ip' => '10.44.0.81',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'workspace',
        'checkout_path' => '/tmp/tasks-spawn-failure',
        'status' => 'reserved',
    ]);

    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private AppInstance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
        {
            return $this->instance;
        }
    });
    app()->instance(AgentSpawner::class, new class implements AgentSpawner
    {
        public function spawnReviewer(Task $task): ?int
        {
            return null;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return null;
        }

        public function requestReview(Task $task): void {}
    });

    $this->postJson('/api/v1/task-groups', [
        'app_id' => $app->id,
        'title' => 'Spawn failure',
        'brief' => 'Fail the group. Accept when create still answers.',
        'status' => 'todo',
        'tasks' => [
            ['title' => 'Only', 'brief' => 'One subtask. Accept when it is recorded.', 'deliverables' => [['id' => 'recorded', 'type' => 'review', 'description' => 'The subtask is recorded.']]],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Spawn failure')
        ->assertJsonPath('data.status', 'running');
    test_pass_baseline();

    expect(TaskGroup::query()->count())->toBe(1)
        ->and(TaskGroup::query()->sole()->status)->toBe(TaskGroupStatus::Failed);
});

it('creates a group with ordered tasks and lists and shows it', function (): void {
    tasks_gateway();
    enable_tasks();
    $app = tasks_app();

    $created = $this->postJson('/api/v1/task-groups', [
        'app_id' => $app->id,
        'title' => 'Absorb Commander',
        'brief' => 'Persist groups. Accept when create and list work.',
        'notify_coder' => true,
        'tasks' => [
            ['title' => 'ADR', 'brief' => 'Write the decision. Accept when reviewers can follow it.'],
            ['title' => 'Models', 'brief' => 'Store TaskGroup and Task. Accept when migrations run.'],
        ],
    ]);

    $created
        ->assertCreated()
        ->assertJsonPath('data.title', 'Absorb Commander')
        ->assertJsonPath('data.app', 'commander-demo')
        ->assertJsonPath('data.status', 'backlog')
        ->assertJsonPath('data.notify_coder', true)
        ->assertJsonPath('data.implementer_model', 'gpt-5.6-luna')
        ->assertJsonPath('data.reviewer_model', 'claude-opus-5')
        ->assertJsonPath('data.taskable_type', null)
        ->assertJsonPath('data.tasks.0.position', 1)
        ->assertJsonPath('data.tasks.0.title', 'ADR')
        ->assertJsonPath('data.tasks.1.position', 2)
        ->assertJsonPath('data.tasks.1.title', 'Models')
        ->assertJsonStructure(['meta' => ['request_id']]);

    $id = $created->json('data.id');

    $this->getJson('/api/v1/task-groups')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.tasks.0.title', 'ADR');

    $this->getJson("/api/v1/task-groups/{$id}")
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.brief', 'Persist groups. Accept when create and list work.');

    $added = $this->postJson("/api/v1/task-groups/{$id}/tasks", [
        'title' => 'Scheduler',
        'brief' => 'Claim under ceilings. Accept when the third group stays queued.',
    ]);

    $added
        ->assertCreated()
        ->assertJsonPath('data.position', 3)
        ->assertJsonPath('data.status', 'todo');

    expect(Task::query()->where('task_group_id', $id)->count())->toBe(3)
        ->and(TaskGroup::query()->findOrFail($id)->status)->toBe(TaskGroupStatus::Backlog);
});

it('creates a fourth group when the App already has three active groups', function (): void {
    tasks_gateway();
    enable_tasks();
    $app = tasks_app('full-create');

    foreach (['One', 'Two', 'Three'] as $title) {
        $this->postJson('/api/v1/task-groups', [
            'app_id' => $app->id,
            'title' => $title,
            'brief' => "{$title} brief",
            'status' => 'todo',
            'tasks' => [['title' => 'Only', 'brief' => 'One subtask.', 'deliverables' => [['id' => 'recorded', 'type' => 'review', 'description' => 'The subtask is recorded.']]]],
        ])->assertCreated()->assertJsonPath('data.status', 'todo');
    }

    $this->postJson('/api/v1/task-groups', [
        'app_id' => $app->id,
        'title' => 'Four',
        'brief' => 'No per-Project ceiling holds this group.',
        'status' => 'todo',
        'tasks' => [['title' => 'Only', 'brief' => 'One subtask.', 'deliverables' => [['id' => 'recorded', 'type' => 'review', 'description' => 'The subtask is recorded.']]]],
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'todo')
        ->assertJsonPath('data.title', 'Four');
});

it('rejects create payloads with missing fields or unsupported keys', function (): void {
    tasks_gateway();
    enable_tasks();

    $this->postJson('/api/v1/task-groups', ['unexpected' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.body.0', 'The request body contains unsupported top-level keys.');

    $this->postJson('/api/v1/task-groups', [
        'title' => 'Missing app',
        'brief' => 'App id is required.',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.app_id.0', 'The app id field is required.');
});

it('returns 403 node_access.required when create comes from a Node without Gateway access', function (): void {
    $gateway = Node::query()->create([
        'name' => 'tasks-gateway-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.81',
        'wireguard_ip' => '10.44.0.81',
    ]);
    $caller = Node::query()->create([
        'name' => 'tasks-worker',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.82',
        'wireguard_ip' => '10.44.0.82',
    ]);
    $this->markAsGateway($gateway);
    $app = tasks_app('no-grant');
    app(TaskExtensionState::class)->enable();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson('/api/v1/task-groups', [
            'app_id' => $app->id,
            'title' => 'Denied',
            'brief' => 'Must not persist.',
        ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');

    expect(TaskGroup::query()->count())->toBe(0);
});

it('lets a Node with a Gateway grant create a group', function (): void {
    $gateway = Node::query()->create([
        'name' => 'tasks-gateway-granted',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.83',
        'wireguard_ip' => '10.44.0.83',
    ]);
    $caller = Node::query()->create([
        'name' => 'tasks-granted-caller',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.84',
        'wireguard_ip' => '10.44.0.84',
    ]);
    $this->markAsGateway($gateway);
    $caller->accessibleNodes()->attach($gateway);
    $app = tasks_app('granted');
    app(TaskExtensionState::class)->enable();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson('/api/v1/task-groups', [
            'app_id' => $app->id,
            'title' => 'Granted',
            'brief' => 'Caller has Gateway access.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Granted');
});

it('completes a settling group and removes its App instance', function (): void {
    tasks_gateway();
    enable_tasks();
    $app = tasks_app('complete-api');
    $node = Node::query()->create([
        'name' => 'complete-api-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.86',
        'wireguard_ip' => '10.44.0.86',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-30',
        'checkout_path' => '/tmp/task-30',
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Ready',
        'brief' => 'PR is merged.',
        'status' => TaskGroupStatus::Settling,
        'pr_url' => 'https://github.com/nckrtl/orbit/pull/543',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $remover = new class implements AppInstanceRemover
    {
        public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
        {
            $instance->delete();

            return new AppInstanceRemoval;
        }
    };
    app()->instance(AppInstanceRemover::class, $remover);

    $this->postJson("/api/v1/task-groups/{$group->id}/complete")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.taskable_id', null)
        ->assertJsonPath('data.pr_url', 'https://github.com/nckrtl/orbit/pull/543');
});

it('returns 409 tasks.not_settling when complete runs before settle', function (): void {
    tasks_gateway();
    enable_tasks();
    $app = tasks_app('too-early');
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Running',
        'brief' => 'Not ready.',
        'status' => TaskGroupStatus::Running,
    ]);

    $this->postJson("/api/v1/task-groups/{$group->id}/complete")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'tasks.not_settling');
});

it('stores the configured models on a new group and keeps the defaults when unset', function (): void {
    tasks_gateway();
    enable_tasks();
    $app = tasks_app();
    config()->set('orbit.tasks.implementer_model', 'gpt-6-luna');
    config()->set('orbit.tasks.reviewer_model', '');

    $this->postJson('/api/v1/task-groups', ['app_id' => $app->id, 'title' => 'Models', 'brief' => 'Configured models'])
        ->assertCreated();

    $this->assertDatabaseHas('task_groups', ['title' => 'Models', 'implementer_model' => 'gpt-6-luna', 'reviewer_model' => TaskAgentDefaults::ReviewerModel]);
});

it('stores the configured implementer and reviewer drivers on a new group', function (): void {
    tasks_gateway();
    enable_tasks();
    $app = tasks_app();
    config()->set('orbit.tasks.implementer_agent_driver', 'pi');
    config()->set('orbit.tasks.reviewer_agent_driver', 't3');

    $this->postJson('/api/v1/task-groups', ['app_id' => $app->id, 'title' => 'Mixed', 'brief' => 'Pi implements, T3 reviews'])
        ->assertCreated();

    $this->assertDatabaseHas('task_groups', ['title' => 'Mixed', 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 't3']);
});

it('rejects an unregistered configured driver with 409 before storing a group', function (string $role): void {
    tasks_gateway();
    enable_tasks();
    $app = tasks_app();
    config()->set("orbit.tasks.{$role}_agent_driver", 'missing-driver');

    $this->postJson('/api/v1/task-groups', ['app_id' => $app->id, 'title' => 'Unavailable', 'brief' => 'No driver'])
        ->assertStatus(409)->assertJsonPath('error.code', 'tasks.agent_driver_unavailable');

    $this->assertDatabaseCount('task_groups', 0);
    $this->assertDatabaseCount('tasks', 0);
})->with(['implementer', 'reviewer']);

it('cancels a running check and shows it on the task', function (): void {
    tasks_gateway();
    enable_tasks();
    $app = tasks_app('checks');
    $node = Node::query()->create(['name' => 'check-dev', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.81', 'wireguard_ip' => '10.44.0.81']);
    $instance = AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $node->id, 'name' => 'task-1', 'checkout_path' => '/srv/apps/checks/task-1', 'status' => 'source_resolved']);
    $group = TaskGroup::query()->create(['app_id' => $app->id, 'title' => 'Checks', 'brief' => 'Run the check.', 'status' => 'running']);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create(['task_group_id' => $group->id, 'position' => 1, 'title' => 'Check', 'brief' => 'Run it.', 'status' => 'running']);
    $receipt = $task->comments()->create(['task_group_id' => $group->id, 'type' => 'ready_for_review', 'body' => 'Done.', 'author' => 'implementer', 'posted_at' => now()]);
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/check/cancel")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'tasks.check_not_running');

    TaskCheck::query()->create([
        'task_id' => $task->id, 'task_comment_id' => $receipt->id, 'status' => TaskCheckStatus::Running, 'pid' => 4001,
        'process_started' => 'Wed Sep 23 12:00:01 2026', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);
    $this->getJson("/api/v1/task-groups/{$group->id}")
        ->assertOk()
        ->assertJsonPath('data.tasks.0.check.status', 'running');

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/check/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.finished_at', fn (mixed $value): bool => is_string($value));
    expect($checks->cancels)->toBe(1);
});
