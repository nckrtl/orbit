<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\QuestionAsker;
use App\Domain\Tasks\QuestionCause;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Api\TaskGroupsController;
use App\Http\Controllers\Api\TasksController;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskQuestion;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
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

function tasks_app(string $slug = 'commander-demo'): Project
{
    return Project::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
        'default_branch' => 'main',
    ]);
}

function enable_tasks(): void
{
    test()->postJson('/api/v1/extensions/tasks/enable')->assertOk()->assertJsonPath('data.enabled', true);
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
        'tasks:status' => ['api/v1/tasks/status', ['GET', 'HEAD']],
        'tasks:list' => ['api/v1/task-groups', ['GET', 'HEAD']],
        'tasks:question:list' => ['api/v1/task-questions', ['GET', 'HEAD']],
        'tasks:create' => ['api/v1/task-groups', ['POST']],
        'tasks:show' => ['api/v1/task-groups/{group}', ['GET', 'HEAD']],
        'tasks:update' => ['api/v1/task-groups/{group}', ['PATCH']],
        'tasks:subtask:create' => ['api/v1/task-groups/{group}/tasks', ['POST']],
        'tasks:subtask:update' => ['api/v1/task-groups/{group}/tasks/{task}', ['PATCH']],
        'tasks:subtask:destroy' => ['api/v1/task-groups/{group}/tasks/{task}', ['DELETE']],
        'tasks:subtask:cancel' => ['api/v1/task-groups/{group}/tasks/{task}/cancel', ['POST']],
        'tasks:check:cancel' => ['api/v1/task-groups/{group}/tasks/{task}/check/cancel', ['POST']],
        'tasks:deliverable:probe' => ['api/v1/task-groups/{group}/tasks/{task}/deliverables/{deliverable}/probe', ['POST']],
        'tasks:check:show' => ['api/v1/task-groups/{group}/tasks/{task}/checks/{check}', ['GET', 'HEAD']],
        'tasks:comment:create' => ['api/v1/task-groups/{group}/tasks/{task}/comments', ['POST']],
        'tasks:comment:list' => ['api/v1/task-groups/{group}/tasks/{task}/comments', ['GET', 'HEAD']],
        'tasks:cancel' => ['api/v1/task-groups/{group}/cancel', ['POST']],
        'tasks:complete' => ['api/v1/task-groups/{group}/complete', ['POST']],
        'tasks:definition:list' => ['api/v1/task-definitions', ['GET', 'HEAD']],
        'tasks:definition:show' => ['api/v1/projects/{project}/task-definitions/{name}', ['GET', 'HEAD']],
        'tasks:definition:create' => ['api/v1/projects/{project}/task-definitions', ['POST']],
        'tasks:definition:update' => ['api/v1/projects/{project}/task-definitions/{name}', ['PUT']],
        'tasks:definition:destroy' => ['api/v1/projects/{project}/task-definitions/{name}', ['DELETE']],
    ]);
});

it('creates and reads operator task comments', function (): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app('comments');
    $group = $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id,
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

it('refuses turn outcomes, which agents report with the turn command', function (string $type): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app('invalid-comments');
    $group = $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id, 'title' => 'Comments', 'brief' => 'Record comments.',
        'tasks' => [['title' => 'Comment task', 'brief' => 'A task.']],
    ])->assertCreated()->json('data');
    $taskId = $group['tasks'][0]['id'];

    $this->postJson("/api/v1/task-groups/{$group['id']}/tasks/{$taskId}/comments", [
        'type' => $type, 'body' => 'Nope', 'author' => 'tester',
    ])->assertStatus(422);
})->with(['ready_for_review', 'changes_requested', 'approved', 'blocked', 'unknown']);

it('declares Gateway access for the extension and group lifecycle and group-owning access for task changes', function (): void {
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

    $this->postJson('/api/v1/extensions/tasks/enable')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonStructure(['meta' => ['request_id']]);

    expect(app(TaskExtensionState::class)->enabled())->toBeTrue();

    $this->postJson('/api/v1/extensions/tasks/disable')
        ->assertOk()
        ->assertJsonPath('data.enabled', false);

    expect(app(TaskExtensionState::class)->enabled())->toBeFalse();
});

it('accepts notify_on_settle as the Commander alias for notify_coder', function (): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app('notify-alias');

    $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id,
        'title' => 'Notify alias',
        'brief' => 'Commander callers send notify_on_settle.',
        'notify_on_settle' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('data.notify_coder', true);
});

it('returns 409 extension.disabled for create and list while the extension is off', function (): void {
    tasks_gateway();
    $project = tasks_app();

    $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id,
        'title' => 'Ship absorb',
        'brief' => 'Deliver the first slice. Accept when MCP create works.',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'extension.disabled')
        ->assertJsonPath('error.message', 'The tasks extension is disabled.');

    $this->getJson('/api/v1/task-groups')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'extension.disabled');

    expect(Task::topLevel()->count())->toBe(0);
});

it('still returns the created group, and fails it when the first implementer cannot start after the baseline', function (): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app('spawn-failure');
    $node = Node::query()->create([
        'name' => 'spawn-failure-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.81',
        'wireguard_ip' => '10.44.0.81',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'workspace',
        'checkout_path' => '/tmp/tasks-spawn-failure',
        'status' => 'reserved',
    ]);

    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
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
        'project_id' => $project->id,
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

    expect(Task::topLevel()->count())->toBe(1)
        ->and(Task::topLevel()->sole()->status)->toBe(TaskGroupStatus::Failed);
});

it('creates a group with ordered tasks and lists and shows it', function (): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app();

    $created = $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id,
        'title' => 'Absorb Commander',
        'brief' => 'Persist groups. Accept when create and list work.',
        'notify_coder' => true,
        'tasks' => [
            ['title' => 'ADR', 'brief' => 'Write the decision. Accept when reviewers can follow it.'],
            ['title' => 'Models', 'brief' => 'Store Task and Task. Accept when migrations run.'],
        ],
    ]);

    $created
        ->assertCreated()
        ->assertJsonPath('data.title', 'Absorb Commander')
        ->assertJsonPath('data.project', 'commander-demo')
        ->assertJsonPath('data.status', 'backlog')
        ->assertJsonPath('data.notify_coder', true)
        ->assertJsonPath('data.implementer_model', 'gpt-5.6-luna')
        ->assertJsonPath('data.reviewer_model', 'gpt-5.6-luna')
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

    expect(Task::query()->where('parent_id', $id)->count())->toBe(3)
        ->and(Task::topLevel()->findOrFail($id)->status)->toBe(TaskGroupStatus::Backlog);
});

it('creates a fourth group when the Project already has three active groups', function (): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app('full-create');

    foreach (['One', 'Two', 'Three'] as $title) {
        $this->postJson('/api/v1/task-groups', [
            'project_id' => $project->id,
            'title' => $title,
            'brief' => "{$title} brief",
            'status' => 'todo',
            'tasks' => [['title' => 'Only', 'brief' => 'One subtask.', 'deliverables' => [['id' => 'recorded', 'type' => 'review', 'description' => 'The subtask is recorded.']]]],
        ])->assertCreated()->assertJsonPath('data.status', 'todo');
    }

    $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id,
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
        ->assertJsonPath('error.details.project_id.0', 'The project id field is required.');
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
    $project = tasks_app('no-grant');
    app(TaskExtensionState::class)->enable();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson('/api/v1/task-groups', [
            'project_id' => $project->id,
            'title' => 'Denied',
            'brief' => 'Must not persist.',
        ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');

    expect(Task::topLevel()->count())->toBe(0);
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
    $project = tasks_app('granted');
    app(TaskExtensionState::class)->enable();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson('/api/v1/task-groups', [
            'project_id' => $project->id,
            'title' => 'Granted',
            'brief' => 'Caller has Gateway access.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Granted');
});

it('completes a settling group and removes its App instance', function (): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app('complete-api');
    $node = Node::query()->create([
        'name' => 'complete-api-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.86',
        'wireguard_ip' => '10.44.0.86',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-30',
        'checkout_path' => '/tmp/task-30',
        'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Ready',
        'brief' => 'PR is merged.',
        'status' => TaskGroupStatus::Settling,
        'pr_url' => 'https://github.com/nckrtl/orbit/pull/543',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $remover = new class implements InstanceRemover
    {
        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    $this->postJson("/api/v1/task-groups/{$group->id}/complete")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.taskable_id', null)
        ->assertJsonPath('data.pr_url', 'https://github.com/nckrtl/orbit/pull/543');
});

it('returns 409 tasks.not_settling when complete runs before settle', function (): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app('too-early');
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
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
    $project = tasks_app();
    config()->set('orbit.tasks.implementer_model', 'gpt-6-luna');
    config()->set('orbit.tasks.reviewer_model', '');

    $this->postJson('/api/v1/task-groups', ['project_id' => $project->id, 'title' => 'Models', 'brief' => 'Configured models'])
        ->assertCreated();

    $this->assertDatabaseHas('tasks', ['title' => 'Models', 'parent_id' => null, 'implementer_model' => 'gpt-6-luna', 'reviewer_model' => TaskAgentDefaults::ReviewerModel]);
});

it('stores pi for both roles when that driver is configured', function (): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app();
    config()->set('orbit.tasks.implementer_agent_driver', 'pi');
    config()->set('orbit.tasks.reviewer_agent_driver', 'pi');

    $this->postJson('/api/v1/task-groups', ['project_id' => $project->id, 'title' => 'Pi', 'brief' => 'Both roles run on Pi'])
        ->assertCreated();

    $this->assertDatabaseHas('tasks', ['title' => 'Pi', 'parent_id' => null, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
});

it('refuses a task for a Project that reads through the GitHub CLI before storing it', function (): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app();
    $project->update(['source_access' => ProjectSourceAccess::GhCli]);

    $this->postJson('/api/v1/task-groups', ['project_id' => $project->id, 'title' => 'Refused', 'brief' => 'No App'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'tasks.github_app_required');

    $this->assertDatabaseMissing('tasks', ['title' => 'Refused']);
});

it('rejects an unregistered configured driver with 409 before storing a group', function (string $role): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app();
    config()->set("orbit.tasks.{$role}_agent_driver", 'missing-driver');

    $this->postJson('/api/v1/task-groups', ['project_id' => $project->id, 'title' => 'Unavailable', 'brief' => 'No driver'])
        ->assertStatus(409)->assertJsonPath('error.code', 'tasks.agent_driver_unavailable');

    $this->assertDatabaseCount('tasks', 0);
})->with(['implementer', 'reviewer']);

it('cancels a running check and shows it on the task', function (): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app('checks');
    $node = Node::query()->create(['name' => 'check-dev', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.81', 'wireguard_ip' => '10.44.0.81']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-1', 'checkout_path' => '/srv/apps/checks/task-1', 'status' => 'source_resolved']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Checks', 'brief' => 'Run the check.', 'status' => 'running']);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Check', 'brief' => 'Run it.', 'status' => 'running']);
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

    Event::fake([RecordBroadcast::class]);
    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/check/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.finished_at', fn (mixed $value): bool => is_string($value));
    expect($checks->cancels)->toBe(1);
    Event::assertDispatched(RecordBroadcast::class, static fn (RecordBroadcast $event): bool => $event->type === RecordEventType::TaskGroupUpdated && $event->id === $group->id);
});

/**
 * `orbit tasks:show --json` and `orbit tasks:list --json` render this DTO, not the raw Gateway body.
 *
 * @param  array<string, mixed>  $group
 * @return array<string, mixed>
 */
function tasks_cli_record(array $group): array
{
    static $loaded = false;

    if (! $loaded) {
        $src = dirname(__DIR__, 5).'/packages/php-sdk/src/Responses/Tasks';
        require_once $src.'/TaskFields.php';
        require_once $src.'/SubtaskResponse.php';
        require_once $src.'/TaskGroupResponse.php';
        $loaded = true;
    }

    return TaskGroupResponse::fromGatewayData($group, 'request')->toArray();
}

it('returns assistance fields on show and list for flagged and unflagged groups', function (): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app('assistance');
    $create = function (string $title) use ($project): array {
        $group = $this->postJson('/api/v1/task-groups', [
            'project_id' => $project->id,
            'title' => $title,
            'brief' => "{$title} brief.",
            'tasks' => [
                ['title' => 'First', 'brief' => 'First brief.'],
                ['title' => 'Second', 'brief' => 'Second brief.'],
            ],
        ])->assertCreated()->json('data');

        expect($group)->toBeArray();

        return $group;
    };
    $flagged = $create('Flagged');
    $clear = $create('Clear');
    $blocked = 'The implementer is blocked.';
    $question = 'Which database should this use?';

    Task::topLevel()->whereKey($flagged['id'])->update([
        'assistance_requested' => true,
        'assistance_kind' => 'direction',
        'assistance_question' => $question,
        'assistance_reason' => $blocked,
    ]);
    Task::query()->whereKey($flagged['tasks'][0]['id'])->update([
        'assistance_requested' => true,
        'assistance_kind' => 'direction',
        'assistance_question' => $question,
        'assistance_reason' => $question,
    ]);

    $expectFields = function (array $group, bool $requested, ?string $kind, ?string $question, ?string $reason, bool $firstRequested, ?string $firstKind, ?string $firstQuestion, ?string $firstReason): void {
        expect($group)->toMatchArray([
            'assistance_requested' => $requested,
            'assistance_kind' => $kind,
            'assistance_question' => $question,
            'assistance_reason' => $reason,
        ])->and($group['tasks'])->toHaveCount(2)
            ->and($group['tasks'][0])->toMatchArray([
                'assistance_requested' => $firstRequested,
                'assistance_kind' => $firstKind,
                'assistance_question' => $firstQuestion,
                'assistance_reason' => $firstReason,
            ])->and($group['tasks'][1])->toMatchArray([
                'assistance_requested' => false,
                'assistance_kind' => null,
                'assistance_question' => null,
                'assistance_reason' => null,
            ]);

        $cli = tasks_cli_record($group);
        expect($cli)->toMatchArray([
            'assistance_requested' => $group['assistance_requested'],
            'assistance_reason' => $group['assistance_reason'],
        ])->and($cli['tasks'][0])->toMatchArray([
            'assistance_requested' => $group['tasks'][0]['assistance_requested'],
            'assistance_reason' => $group['tasks'][0]['assistance_reason'],
        ])->and($cli['tasks'][1])->toMatchArray([
            'assistance_requested' => $group['tasks'][1]['assistance_requested'],
            'assistance_reason' => $group['tasks'][1]['assistance_reason'],
        ]);
    };

    $shown = $this->getJson('/api/v1/task-groups/'.$flagged['id'])->assertOk()->json('data');
    $clearShown = $this->getJson('/api/v1/task-groups/'.$clear['id'])->assertOk()->json('data');
    expect($shown)->toBeArray()->and($clearShown)->toBeArray();
    $expectFields($shown, true, 'direction', $question, $blocked, true, 'direction', $question, $question);
    $expectFields($clearShown, false, null, null, null, false, null, null, null);

    $listed = collect($this->getJson('/api/v1/task-groups')->assertOk()->json('data'))->keyBy('id');
    $flaggedList = $listed->get($flagged['id']);
    $clearList = $listed->get($clear['id']);
    expect($flaggedList)->toBeArray()->and($clearList)->toBeArray();
    $expectFields($flaggedList, true, 'direction', $question, $blocked, true, 'direction', $question, $question);
    $expectFields($clearList, false, null, null, null, false, null, null, null);
});

it('summarises groups asking for assistance on tasks status', function (): void {
    tasks_gateway();
    $project = tasks_app('assist-status');

    $this->getJson('/api/v1/tasks/status')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.assistance', []);

    enable_tasks();
    $create = function (string $title) use ($project): int {
        $id = $this->postJson('/api/v1/task-groups', [
            'project_id' => $project->id,
            'title' => $title,
            'brief' => "{$title} brief.",
            'tasks' => [['title' => 'Step', 'brief' => 'Step brief.']],
        ])->assertCreated()->json('data.id');

        expect($id)->toBeInt();

        return $id;
    };
    $first = $create('First stalled');
    $clear = $create('Clear');
    $second = $create('Second stalled');
    $subtaskOnly = $create('Subtask only');
    $question = 'Which database should this use?';

    Task::topLevel()->whereKey($first)->update([
        'status' => 'running',
        'assistance_requested' => true,
        'assistance_kind' => 'direction',
        'assistance_question' => $question,
        'assistance_reason' => 'The implementer is blocked.',
    ]);
    Task::topLevel()->whereKey($clear)->update([
        'assistance_requested' => false,
        'assistance_reason' => 'An old reason.',
    ]);
    Task::topLevel()->whereKey($second)->update([
        'status' => 'settling',
        'assistance_requested' => true,
        'assistance_kind' => 'failure',
        'assistance_question' => null,
        'assistance_reason' => null,
    ]);
    Task::query()->where('parent_id', $subtaskOnly)->update([
        'assistance_requested' => true,
        'assistance_reason' => $question,
    ]);

    $assistance = [
        [
            'id' => $first,
            'project_id' => $project->id,
            'project' => $project->slug,
            'project_code' => $project->code,
            'title' => 'First stalled',
            'status' => 'running',
            'assistance_kind' => 'direction',
            'assistance_question' => $question,
            'assistance_reason' => 'The implementer is blocked.',
        ],
        [
            'id' => $second,
            'project_id' => $project->id,
            'project' => $project->slug,
            'project_code' => $project->code,
            'title' => 'Second stalled',
            'status' => 'settling',
            'assistance_kind' => 'failure',
            'assistance_question' => null,
            'assistance_reason' => null,
        ],
    ];

    $this->getJson('/api/v1/tasks/status')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.assistance', $assistance);

    $this->postJson('/api/v1/extensions/tasks/disable')
        ->assertOk()
        ->assertJsonMissingPath('data.assistance')
        ->assertJsonPath('data.enabled', false);

    $this->getJson('/api/v1/tasks/status')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.assistance', $assistance);
});

it('lists task questions newest first and filters by project, cause, status, and time', function (): void {
    tasks_gateway();
    $this->getJson('/api/v1/task-questions')->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
    enable_tasks();
    $project = tasks_app('questions');
    $other = tasks_app('questions-other');
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Questions', 'brief' => 'Record them.', 'status' => TaskGroupStatus::Running,
    ]);
    $subtask = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Ask', 'brief' => 'One question.', 'status' => 'running',
    ]);
    $elsewhere = Task::topLevel()->create([
        'project_id' => $other->id, 'title' => 'Other', 'brief' => 'Another project.', 'status' => TaskGroupStatus::Running,
    ]);
    $otherSubtask = Task::query()->create([
        'parent_id' => $elsewhere->id, 'position' => 1, 'title' => 'Other ask', 'brief' => 'Elsewhere.', 'status' => 'running',
    ]);
    $older = TaskQuestion::query()->create([
        'task_id' => $group->id, 'subtask_id' => $subtask->id, 'attempt' => 1, 'asked_by' => QuestionAsker::Implementer,
        'question' => 'Which mirror?', 'status' => QuestionStatus::Escalated, 'asked_at' => '2026-10-01 00:00:00', 'escalated_at' => '2026-10-01 00:00:00',
    ]);
    $newer = TaskQuestion::query()->create([
        'task_id' => $group->id, 'subtask_id' => $subtask->id, 'attempt' => 2, 'asked_by' => QuestionAsker::Reviewer,
        'question' => 'Which ADR?', 'status' => QuestionStatus::Answered, 'answered_by' => QuestionAsker::Operator,
        'answer' => 'Follow the ADR.', 'cause' => QuestionCause::ContractGap, 'asked_at' => '2026-10-07 12:00:00',
        'escalated_at' => '2026-10-07 12:00:00', 'answered_at' => '2026-10-07 13:00:00',
        'opened_comment_id' => 9, 'resolution_comment_id' => 10, 'answered_comment_id' => 11,
    ]);
    TaskQuestion::query()->create([
        'task_id' => $elsewhere->id, 'subtask_id' => $otherSubtask->id, 'attempt' => 1, 'asked_by' => QuestionAsker::Operator,
        'question' => 'Other project', 'status' => QuestionStatus::Open, 'cause' => QuestionCause::Scope, 'asked_at' => '2026-10-08 00:00:00',
    ]);

    $this->getJson('/api/v1/task-questions?project_id='.$project->id.'&cause=contract_gap&status=answered&since=2026-10-07T00:00:00Z')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.0.asked_by', 'reviewer')
        ->assertJsonPath('data.0.cause', 'contract_gap')
        ->assertJsonPath('data.0.answer', 'Follow the ADR.')
        ->assertJsonMissingPath('data.0.opened_comment_id')
        ->assertJsonMissingPath('data.0.resolution_comment_id');

    $this->getJson('/api/v1/task-questions?project_id='.$project->id)
        ->assertOk()
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id);

    $this->getJson('/api/v1/task-questions?project_id='.$other->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.question', 'Other project');

    $this->getJson('/api/v1/task-questions?cause=contract_gap')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $newer->id);

    $this->getJson('/api/v1/task-questions?status=escalated')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $older->id);

    $this->getJson('/api/v1/task-questions?since=2026-10-08T00:00:00Z')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.question', 'Other project');

    $this->getJson('/api/v1/task-questions?cause=nope')->assertUnprocessable();
});

it('returns 403 node_access.required when listing questions without collection access', function (): void {
    $gateway = Node::query()->create([
        'name' => 'question-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.91', 'wireguard_ip' => '10.44.0.91',
    ]);
    $caller = Node::query()->create([
        'name' => 'question-worker', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.92', 'wireguard_ip' => '10.44.0.92',
    ]);
    $this->markAsGateway($gateway);
    app(TaskExtensionState::class)->enable();

    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->getJson('/api/v1/task-questions')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');

    $caller->accessibleNodes()->attach($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->getJson('/api/v1/task-questions')
        ->assertOk()
        ->assertJsonPath('data', []);
});
