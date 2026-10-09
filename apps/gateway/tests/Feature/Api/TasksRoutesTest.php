<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\DeliverablePathChecker;
use App\Domain\Tasks\DeliverablePathRepository;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\QuestionAsker;
use App\Domain\Tasks\QuestionCause;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Api\TaskGroupsController;
use App\Http\Controllers\Api\TasksController;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Tasks\NativeDeliverablePathRepository;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use App\Rules\CommandPaths;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Symfony\Component\Process\Process;
use Tests\Support\DeliverablePathWorkspace;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\TestOrbitHome;

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
        'apps' => fixture_apps(null),
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
        'tasks:question:close' => ['api/v1/task-questions/{question}/close', ['POST']],
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

it('reports when the last tasks tick started its work on tasks status', function (): void {
    tasks_gateway();
    $this->travelTo(Carbon::parse('2026-10-07T06:00:00Z'));

    $this->artisan('tasks:tick')->assertSuccessful();
    $this->getJson('/api/v1/tasks/status')
        ->assertOk()
        ->assertJsonPath('data.last_tick_at', null);

    enable_tasks();
    $this->artisan('tasks:tick')->assertSuccessful();
    $this->getJson('/api/v1/tasks/status')
        ->assertOk()
        ->assertJsonPath('data.last_tick_at', '2026-10-07T06:00:00.000000Z');

    $this->travelTo(Carbon::parse('2026-10-07T06:00:10Z'));
    $held = Cache::lock('orbit:tasks:tick', 300);
    expect($held->get())->toBeTrue();
    $this->artisan('tasks:tick')
        ->expectsOutput('Another tasks tick is already running.')
        ->assertSuccessful();
    $held->release();
    $this->getJson('/api/v1/tasks/status')
        ->assertOk()
        ->assertJsonPath('data.last_tick_at', '2026-10-07T06:00:00.000000Z');

    $this->artisan('tasks:tick')->assertSuccessful();
    $this->postJson('/api/v1/extensions/tasks/disable')
        ->assertOk()
        ->assertJsonMissingPath('data.last_tick_at');
    $this->getJson('/api/v1/tasks/status')
        ->assertOk()
        ->assertJsonPath('data.last_tick_at', '2026-10-07T06:00:10.000000Z');
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
        'starting_commit' => str_repeat('a', 40),
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
        require_once $src.'/TaskReviewAndMergeResponse.php';
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

function deliverable_path_repository(): object
{
    if (! interface_exists(DeliverablePathRepository::class)) {
        return new stdClass;
    }
    $repository = new class implements DeliverablePathRepository
    {
        public int $defaultLookups = 0;

        /** @var list<string> */
        public array $commits = [];

        public function defaultBranchCommit(Project $project): string
        {
            expect($project->default_branch)->toBe('main');
            $this->defaultLookups++;

            return str_repeat('a', 40);
        }

        public function files(Project $project, string $commit, ?Instance $workspace = null): array
        {
            $this->commits[] = $commit;

            return $commit === str_repeat('a', 40)
                ? ['tests/ExistingTest.php', 'app/OnlyOnMain.php', 'docs/reference/tasks.md']
                : ['tests/ExistingTest.php', 'tests/OnlyOnOtherRefTest.php'];
        }
    };
    app()->instance(DeliverablePathRepository::class, $repository);

    return $repository;
}

function deliverable_path_file(string $path, string $change = 'modified'): array
{
    return ['id' => 'file-check', 'type' => 'file', 'description' => 'Check the file.', 'path' => $path, 'change' => $change];
}

it('uses the provisional_base default branch SHA for workspace-less create', function (string $status): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app();
    $repository = deliverable_path_repository();
    $response = $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id, 'title' => 'Paths', 'brief' => 'Validate paths.', 'status' => $status,
        'tasks' => [['title' => 'Check', 'brief' => 'Check.', 'deliverables' => [deliverable_path_file('tests/OnlyOnOtherRefTest.php')]]],
    ])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect($response->json('error.details')['tasks.0.deliverables.0.path'][0])->toBe(
        'Deliverable file-check path tests/OnlyOnOtherRefTest.php is missing on base '.str_repeat('a', 40).' (base_kind=provisional).',
    );
    expect($repository->defaultLookups)->toBe(1)->and($repository->commits)->toBe([str_repeat('a', 40)]);
    expect(Task::query()->count())->toBe(0);
})->with(['backlog', 'todo']);

it('uses the resolved_base directly for subtask create and update', function (string $source, string $operation): void {
    $gateway = tasks_gateway();
    enable_tasks();
    $project = tasks_app();
    $repository = deliverable_path_repository();
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Paths', 'brief' => 'Paths.', 'status' => TaskGroupStatus::Backlog]);
    $base = str_repeat('b', 40);
    if ($source === 'workspace') {
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $gateway->id, 'name' => 'path-base', 'checkout_path' => '/srv/apps/path-base', 'starting_commit' => $base, 'status' => 'source_resolved']);
        $group->taskable()->associate($instance);
        $group->save();
    } elseif ($source === 'approved') {
        $previous = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Earlier', 'brief' => 'Earlier.', 'status' => TaskStatus::Completed]);
        TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $previous->id, 'type' => TaskCommentType::Approved, 'body' => 'Approved.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => $base]);
    }
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Check', 'brief' => 'Check.', 'status' => TaskStatus::Todo, 'subtask_start_commit' => $source === 'recorded' ? $base : null]);
    $payload = ['title' => 'Check', 'brief' => 'Check.', 'deliverables' => [deliverable_path_file('app/OnlyOnMain.php')]];
    $response = $operation === 'create'
        ? $this->postJson("/api/v1/task-groups/{$group->id}/tasks", $payload)
        : $this->patchJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}", $payload);
    $response->assertUnprocessable();
    expect($response->json('error.details')['deliverables.0.path'][0])->toBe('Deliverable file-check path app/OnlyOnMain.php is missing on base '.$base.' (base_kind=resolved).');
    expect($repository->defaultLookups)->toBe(0)->and($repository->commits)->toBe([$base]);
})->with([['workspace', 'create'], ['workspace', 'update'], ['approved', 'create'], ['approved', 'update'], ['recorded', 'update']]);

it('validates deliverable.path on existing groups after a GhCli source access change', function (string $operation, string $kind): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app();
    $repository = deliverable_path_repository();
    $group = $this->postJson('/api/v1/task-groups', [
        'project_id' => $project->id, 'title' => 'Paths', 'brief' => 'Validate paths.',
        'tasks' => [['title' => 'Check', 'brief' => 'Check.']],
    ])->assertCreated()->json('data');
    $taskId = $group['tasks'][0]['id'];
    $project->update(['source_access' => ProjectSourceAccess::GhCli]);
    $deliverable = match ($kind) {
        'file' => deliverable_path_file('missing.php'),
        'missing-command' => ['id' => 'base-check', 'type' => 'command', 'description' => 'Run.', 'command' => 'vendor/bin/pest', 'paths' => ['tests/MissingTest.php']],
        default => ['id' => 'base-check', 'type' => 'command', 'description' => 'Run.', 'command' => 'vendor/bin/pest', 'fails_on_base' => true, 'paths' => ['app/OnlyOnMain.php']],
    };
    $payload = ['title' => 'Check', 'brief' => 'Check.', 'deliverables' => [$deliverable]];
    $response = $operation === 'create'
        ? $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", $payload)
        : $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$taskId}", $payload);
    $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    $field = $kind === 'file' ? 'deliverables.0.path' : 'deliverables.0.paths.0';
    $reason = match ($kind) {
        'file' => 'Deliverable file-check path missing.php is missing',
        'missing-command' => 'Deliverable base-check path tests/MissingTest.php is missing',
        default => 'Deliverable base-check path app/OnlyOnMain.php must be a test file for fails_on_base',
    };
    expect($response->json('error.details')[$field][0])->toBe($reason.' on base '.str_repeat('a', 40).' (base_kind=provisional).');
    expect($repository->defaultLookups)->toBe(1)->and($repository->commits)->toBe([str_repeat('a', 40)]);
    expect(Task::query()->where('parent_id', $group['id'])->count())->toBe(1);
    expect(Task::query()->findOrFail($taskId)->deliverables)->toBe([]);
})->with(['create', 'update'])->with(['file', 'missing-command', 'implementation-overlay']);

it('checks deliverable.path and fails_on_base command paths at every request boundary', function (array $deliverables, ?string $field, ?string $reason): void {
    tasks_gateway();
    enable_tasks();
    $project = tasks_app();
    deliverable_path_repository();
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Paths', 'brief' => 'Paths.', 'status' => TaskGroupStatus::Backlog]);
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Check', 'brief' => 'Check.', 'status' => TaskStatus::Todo]);
    $payload = ['title' => 'Check', 'brief' => 'Check.', 'deliverables' => $deliverables];
    $responses = [
        [$this->postJson('/api/v1/task-groups', ['project_id' => $project->id, 'title' => 'Paths', 'brief' => 'Paths.', 'tasks' => [$payload]]), 'tasks.0.deliverables.'],
        [$this->postJson("/api/v1/task-groups/{$group->id}/tasks", $payload), 'deliverables.'],
        [$this->patchJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}", $payload), 'deliverables.'],
    ];
    foreach ($responses as [$response, $prefix]) {
        if ($field === null) {
            expect($response->status())->toBeIn([200, 201]);
        } else {
            $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
            expect($response->json('error.details')[$prefix.$field][0])->toContain($reason, str_repeat('a', 40), 'base_kind=provisional');
        }
    }
})->with(function (): array {
    $command = static fn (string $path, bool $base = true): array => ['id' => 'base-check', 'type' => 'command', 'description' => 'Run.', 'command' => 'vendor/bin/pest', 'fails_on_base' => $base, 'paths' => [$path]];

    return [
        'path_missing literal' => [[deliverable_path_file('missing.php')], '0.path', 'file-check path missing.php is missing'],
        'zero match glob' => [[deliverable_path_file('tests/{Unit,Feature}/Missing*.php')], '0.path', 'file-check path tests/{Unit,Feature}/Missing*.php is missing'],
        'created file' => [[deliverable_path_file('missing.php', 'created')], null, null],
        'brace glob exists' => [[deliverable_path_file('docs/{reference,decisions}/tasks.md')], null, null],
        'relative file prefix' => [[deliverable_path_file('./docs/reference/tasks.md')], null, null],
        'relative glob prefix' => [[deliverable_path_file('./docs/{reference,decisions}/tasks.*')], null, null],
        'relative missing file preserves submitted path' => [[deliverable_path_file('./missing.php')], '0.path', 'file-check path ./missing.php is missing'],
        'relative missing glob preserves submitted path' => [[deliverable_path_file('./tests/Missing*.php')], '0.path', 'file-check path ./tests/Missing*.php is missing'],
        'fails_on_base implementation' => [[$command('app/OnlyOnMain.php')], '0.paths.0', 'base-check path app/OnlyOnMain.php must be a test file'],
        'fails_on_base existing test' => [[$command('tests/ExistingTest.php')], null, null],
        'unmarked command path' => [[$command('tests/NewTest.php')], '0.paths.0', 'base-check path tests/NewTest.php is missing'],
        'created literal companion' => [[$command('tests/NewTest.php'), deliverable_path_file('tests/NewTest.php', 'created')], null, null],
        'created glob companion' => [[$command('tests/NewTest.php'), deliverable_path_file('tests/{New,Other}*.php', 'created')], null, null],
        'relative created literal companion' => [[$command('tests/NewTest.php'), deliverable_path_file('./tests/NewTest.php', 'created')], null, null],
        'relative created glob companion' => [[$command('tests/NewTest.php'), deliverable_path_file('./tests/{New,Other}*.php', 'created')], null, null],
        'modified companion' => [[$command('tests/NewTest.php'), deliverable_path_file('tests/*Test.php', 'modified')], '0.paths.0', 'base-check path tests/NewTest.php is missing'],
        'any companion' => [[$command('tests/NewTest.php'), deliverable_path_file('tests/*Test.php', 'any')], '0.paths.0', 'base-check path tests/NewTest.php is missing'],
        'ordinary command missing path' => [[$command('app/Missing.php', false)], '0.paths.0', 'base-check path app/Missing.php is missing'],
        'ordinary implementation command' => [[$command('app/OnlyOnMain.php', false)], null, null],
        'PHP test suffix' => [[$command('integration/NewTest.php'), deliverable_path_file('integration/NewTest.php', 'created')], null, null],
        'TS test suffix' => [[$command('src/new.test.ts'), deliverable_path_file('src/new.test.ts', 'created')], null, null],
        'TS spec suffix' => [[$command('src/new.spec.ts'), deliverable_path_file('src/new.spec.ts', 'created')], null, null],
        'Go test suffix' => [[$command('pkg/new_test.go'), deliverable_path_file('pkg/new_test.go', 'created')], null, null],
        'nested tests directory' => [[$command('apps/gateway/tests/new.php'), deliverable_path_file('apps/gateway/tests/new.php', 'created')], null, null],
        'created implementation still refused' => [[$command('app/New.php'), deliverable_path_file('app/New.php', 'created')], '0.paths.0', 'base-check path app/New.php must be a test file'],
    ];
});

function deliverable_correction_task(?string $failedStep = 'invalid_deliverable'): Task
{
    tasks_gateway();
    enable_tasks();
    deliverable_path_repository();
    $project = tasks_app('correction');
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Correction', 'brief' => 'Keep the work.', 'status' => TaskGroupStatus::Running, 'assistance_requested' => true, 'assistance_reason' => 'Invalid overlay path.']);
    $task = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Started', 'brief' => 'Keep this brief.',
        'status' => TaskStatus::Running, 'subtask_start_commit' => str_repeat('b', 40),
        'deliverables' => [deliverable_path_file('tests/MissingTest.php')],
        'assistance_requested' => true, 'assistance_reason' => 'Invalid overlay path.',
        'completion_attempt' => 2,
    ]);
    $receipt = TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'type' => TaskCommentType::ReadyForReview, 'body' => 'Ready.', 'author' => 'implementer', 'posted_at' => now(), 'completion_attempt' => 2]);
    $task->update(['completion_handoff_comment_id' => $receipt->id]);
    TaskCheck::query()->create([
        'task_id' => $task->id, 'task_comment_id' => $receipt->id, 'kind' => TaskCheckKind::Handoff,
        'status' => TaskCheckStatus::Failed, 'failed_step' => $failedStep,
        'pid' => 123, 'process_started' => 'check-start', 'head_before' => str_repeat('c', 40), 'tree_before' => str_repeat('d', 40),
        'started_at' => now(), 'finished_at' => now(),
    ]);

    return $task;
}

it('allows one invalid_deliverable correction and audits it without discarding work', function (): void {
    $task = deliverable_correction_task();
    $before = $task->deliverables;
    $replacement = [deliverable_path_file('tests/ExistingTest.php')];
    $url = "/api/v1/task-groups/{$task->parent_id}/tasks/{$task->id}";

    $requestId = '19c10948-0ca3-4c1a-b1d0-6c99eb4c05f5';
    $actor = Node::query()->where('name', 'tasks-gateway')->sole();
    $this->withHeader('X-Orbit-Request-Id', $requestId)->patchJson($url, ['deliverables' => $replacement])->assertOk()->assertJsonPath('data.deliverables', $replacement);

    $task->refresh();
    expect($task->deliverables)->toBe($replacement)
        ->and($task->status)->toBe(TaskStatus::Running)
        ->and($task->subtask_start_commit)->toBe(str_repeat('b', 40))
        ->and($task->completion_attempt)->toBe(2)
        ->and($task->assistance_requested)->toBeTrue();
    $audit = Activity::query()->where('subject_id', $task->id)->where('description', 'deliverables corrected')->sole();
    expect($audit->getRawOriginal('caller_node_id'))->toBe($actor->id);
    expect($audit->caller_ip)->toBe($actor->wireguard_ip);
    expect($audit->request_id)->toBe($requestId);
    expect($audit->properties?->get('old'))->toBe($before)
        ->and($audit->properties?->get('new'))->toBe($replacement)
        ->and($audit->properties?->get('check_id'))->toBe(TaskCheck::query()->where('task_id', $task->id)->sole()->id);

    $this->patchJson($url, ['deliverables' => $replacement])->assertConflict()->assertJsonPath('error.code', 'tasks.deliverables_locked');
    $check = TaskCheck::query()->where('task_id', $task->id)->sole();
    $again = $check->replicate();
    $again->save();
    $this->patchJson($url, ['deliverables' => $replacement])->assertConflict()->assertJsonPath('error.code', 'tasks.deliverables_locked');
    expect($task->fresh()?->deliverables)->toBe($replacement);
    expect(Activity::query()->where('subject_id', $task->id)->where('description', 'deliverables corrected')->count())->toBe(1);
});

it('does not reopen a deliverable correction after activity cleanup and another invalid handoff', function (): void {
    $task = deliverable_correction_task();
    $url = "/api/v1/task-groups/{$task->parent_id}/tasks/{$task->id}";
    $replacement = [deliverable_path_file('tests/ExistingTest.php')];
    $this->patchJson($url, ['deliverables' => $replacement])->assertOk();
    $consumed = $task->fresh()?->deliverable_correction_check_id;
    Activity::query()->where('log_name', 'tasks')->update(['created_at' => now()->subDays(400)]);

    $this->artisan('activitylog:clean', ['log' => 'tasks', '--days' => 1])->assertExitCode(0);

    expect(Activity::query()->where('description', 'deliverables corrected')->count())->toBe(0);
    $again = TaskCheck::query()->where('task_id', $task->id)->sole()->replicate();
    $again->save();
    $this->patchJson($url, ['deliverables' => $replacement])->assertConflict()->assertJsonPath('error.code', 'tasks.deliverables_locked');
    expect($task->fresh()?->deliverable_correction_check_id)->toBe($consumed)->not->toBeNull();
});

it('keeps deliverables_locked for ordinary running edits and unrelated failures', function (?string $failedStep): void {
    $task = deliverable_correction_task($failedStep);
    $before = $task->deliverables;

    $this->patchJson("/api/v1/task-groups/{$task->parent_id}/tasks/{$task->id}", ['deliverables' => [deliverable_path_file('tests/ExistingTest.php')]])
        ->assertConflict()->assertJsonPath('error.code', 'tasks.deliverables_locked');

    expect($task->fresh()?->deliverables)->toBe($before);
})->with([null, 'project_check']);

it('keeps deliverable correction locked outside the failed handoff assistance window', function (string $case): void {
    $task = deliverable_correction_task();
    $before = $task->deliverables;
    if ($case === 'not-assisted') {
        $task->update(['assistance_requested' => false, 'assistance_reason' => null]);
    } elseif ($case === 'new-attempt') {
        $task->update(['completion_attempt' => 3]);
    } elseif ($case === 'reviewing') {
        $task->update(['status' => TaskStatus::Reviewing]);
    } elseif ($case === 'closed-group') {
        $task->parent->update(['status' => TaskGroupStatus::Cancelled]);
    } elseif ($case === 'old-receipt') {
        $task->update(['completion_handoff_comment_id' => null]);
    } else {
        $newer = TaskCheck::query()->where('task_id', $task->id)->sole()->replicate();
        $newer->status = TaskCheckStatus::Passed;
        $newer->save();
    }

    $this->patchJson("/api/v1/task-groups/{$task->parent_id}/tasks/{$task->id}", ['deliverables' => [deliverable_path_file('tests/ExistingTest.php')]])
        ->assertConflict()->assertJsonPath('error.code', 'tasks.deliverables_locked');

    expect($task->fresh()?->deliverables)->toBe($before);
})->with(['not-assisted', 'new-attempt', 'reviewing', 'closed-group', 'old-receipt', 'newer-check']);

it('validates a deliverable correction without consuming it on refused requests', function (): void {
    $task = deliverable_correction_task();
    $before = $task->deliverables;
    $url = "/api/v1/task-groups/{$task->parent_id}/tasks/{$task->id}";
    $command = ['id' => 'repro', 'type' => 'command', 'description' => 'Reproduce.', 'command' => 'vendor/bin/pest', 'fails_on_base' => true, 'paths' => ['app/New.php']];

    $response = $this->patchJson($url, ['deliverables' => [$command, deliverable_path_file('app/New.php', 'created')]])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect($response->json('error.details')['deliverables.0.paths.0'][0])->toContain('must be a test file', str_repeat('b', 40), 'base_kind=resolved');
    $this->patchJson($url, ['deliverables' => [deliverable_path_file('tests/MissingTest.php')]])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    $this->patchJson($url, ['deliverables' => []])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.subtask_deliverables_missing');
    $this->patchJson($url, ['title' => 'Changed', 'deliverables' => [deliverable_path_file('tests/ExistingTest.php')]])->assertConflict()->assertJsonPath('error.code', 'tasks.not_in_backlog');
    $this->patchJson($url, ['topology' => ['app-dev'], 'deliverables' => [deliverable_path_file('tests/ExistingTest.php')]])->assertConflict()->assertJsonPath('error.code', 'tasks.not_in_backlog');
    expect($task->fresh()?->deliverables)->toBe($before)
        ->and($task->fresh()?->topology)->toBeNull();
    expect(Activity::query()->where('subject_id', $task->id)->where('description', 'deliverables corrected')->count())->toBe(0);

    $command['paths'] = ['tests/ExistingTest.php'];
    $this->patchJson($url, ['deliverables' => [$command]])->assertOk();
    expect($task->fresh()?->deliverables[0]['fails_on_base'])->toBeTrue();
});

it('allows DeliverablePathChecker callers to supply an explicit commit without default branch lookup', function (): void {
    $repository = deliverable_path_repository();
    $errors = app(DeliverablePathChecker::class)->check(tasks_app(), [deliverable_path_file('app/OnlyOnMain.php')], str_repeat('b', 40));
    expect($errors['0.path'])->toContain(str_repeat('b', 40), 'base_kind=resolved');
    expect($repository->defaultLookups)->toBe(0)->and($repository->commits)->toBe([str_repeat('b', 40)]);
});

it('accepts a subtask appended after a review-and-merge approval that exists only in the task workspace', function (): void {
    $gateway = tasks_gateway();
    enable_tasks();
    $project = tasks_app('local-approval');
    $project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
    $git = DeliverablePathWorkspace::repositories();
    $origin = new class implements ProcessRunner
    {
        public int $reads = 0;

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->reads++;

            return new CommandResult(128, '', 'fatal: unable to access origin', 0, false);
        }
    };
    app()->instance(DeliverablePathRepository::class, new NativeDeliverablePathRepository($origin, app(RepositoryReadAccess::class), new Filesystem, DeliverablePathWorkspace::localExecutor()));
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Local approval', 'brief' => 'Keep approvals local.', 'status' => TaskGroupStatus::Running]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $gateway->id, 'name' => 'local-approval', 'checkout_path' => $git['checkout'], 'starting_commit' => $git['pushed'], 'status' => 'source_resolved']);
    $group->taskable()->associate($instance);
    $group->save();
    $previous = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Earlier', 'brief' => 'Earlier.', 'status' => TaskStatus::Completed]);
    TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $previous->id, 'type' => TaskCommentType::Approved, 'body' => 'Approved.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => $git['local']]);

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks", [
        'title' => 'Follow up', 'brief' => 'Change the approved file.', 'deliverables' => [deliverable_path_file('app/LocalOnly.php')],
    ])->assertCreated()->assertJsonPath('data.position', 2);
    $this->postJson("/api/v1/task-groups/{$group->id}/tasks", [
        'title' => 'Missing', 'brief' => 'Name a missing file.', 'deliverables' => [deliverable_path_file('app/Absent.php')],
    ])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect($origin->reads)->toBe(0);

    TaskComment::query()->where('commit_sha', $git['local'])->update(['commit_sha' => str_repeat('e', 40)]);
    $this->postJson("/api/v1/task-groups/{$group->id}/tasks", [
        'title' => 'Unknown base', 'brief' => 'The base is in neither source.', 'deliverables' => [deliverable_path_file('app/Pushed.php')],
    ])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.deliverable_base_unavailable');
    TestOrbitHome::clearScratch();
});

it('refuses non-canonical command paths that the handoff check would reject', function (?string $path): void {
    tasks_gateway();
    enable_tasks();
    deliverable_path_repository();
    $project = tasks_app('command-paths');
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Paths', 'brief' => 'Paths.', 'status' => TaskGroupStatus::Backlog]);
    $command = ['id' => 'repro', 'type' => 'command', 'description' => 'Reproduce.', 'command' => 'vendor/bin/pest', 'fails_on_base' => true, 'paths' => [$path ?? 'tests/ExistingTest.php']];

    $response = $this->postJson("/api/v1/task-groups/{$group->id}/tasks", ['title' => 'Repro', 'brief' => 'Repro.', 'deliverables' => [$command]]);

    if ($path === null) {
        $response->assertCreated();

        return;
    }
    $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect($response->json('error.details')['deliverables.0.paths'][0])
        ->toBe("The path {$path} for deliverable repro is not canonical; use the canonical repository-relative path tests/ExistingTest.php.");
    expect(Task::query()->where('parent_id', $group->id)->count())->toBe(0);
})->with([
    'leading dot segment' => './tests/ExistingTest.php',
    'empty segment' => 'tests//ExistingTest.php',
    'dot and empty segments' => './/tests/ExistingTest.php',
    'inner dot segment' => 'tests/./ExistingTest.php',
    'canonical' => null,
]);

it('accepts at plan time exactly the command paths that the handoff check accepts', function (): void {
    $paths = ['tests/ExistingTest.php', 'apps/gateway/tests/Feature/HomeScreenTest.php', './tests/ExistingTest.php', 'tests//ExistingTest.php',
        './/tests/ExistingTest.php', 'tests/./ExistingTest.php', 'tests/', '.', '/tests/ExistingTest.php', '../ExistingTest.php', 'tests/../ExistingTest.php'];
    $checkout = TestOrbitHome::scratch('command-path-parity');
    mkdir($checkout, 0700, true);
    $program = <<<'PY'
        import importlib.machinery, importlib.util, json, sys
        loader = importlib.machinery.SourceFileLoader('task_check', sys.argv[1])
        spec = importlib.util.spec_from_loader('task_check', loader)
        module = importlib.util.module_from_spec(spec)
        loader.exec_module(module)
        print(json.dumps([module.deliverable_path(sys.argv[2], path) is not None for path in json.loads(sys.argv[3])]))
        PY;
    $process = new Process(['python3', '-I', '-c', $program, resource_path('tasks/check'), $checkout, json_encode($paths, JSON_THROW_ON_ERROR)]);
    $process->mustRun();
    $handoff = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    $plan = array_map(static fn (string $path): bool => preg_match('#(?:\A/|(?:\A|/)\.\.(?:/|\z))#', $path) !== 1
        && CommandPaths::canonicalViolation($path) === null, $paths);

    expect(array_combine($paths, $plan))->toBe(array_combine($paths, $handoff));
    TestOrbitHome::clearScratch();
});
