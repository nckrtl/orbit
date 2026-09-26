<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\NullAgentSpawner;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPlannerMcp;
use App\Domain\Tasks\TaskPlannerSpawner;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\TaskGroup;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'planner-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.91',
        'wireguard_ip' => '10.44.0.91',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $this->appRecord = OrbitApp::query()->create([
        'name' => 'Planner demo',
        'slug' => 'planner-demo',
        'repository_url' => 'git@example.test:planner-demo.git',
        'default_branch' => 'main',
    ]);
    $this->workspaceNode = Node::query()->create([
        'name' => 'planner-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.92',
        'wireguard_ip' => '10.44.0.92',
    ]);

    $this->provisioning = new class($this->workspaceNode) implements InstanceProvisioning
    {
        /** @var list<InstanceProvisionIntent> */
        public array $intents = [];

        public bool $refuse = false;

        public function __construct(private Node $node) {}

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
        {
            $this->intents[] = $intent;
            $existing = $intent->group->taskable;

            if ($existing instanceof AppInstance) {
                return $existing;
            }

            return $this->refuse ? null : AppInstance::query()->create([
                'app_id' => $intent->group->app_id,
                'node_id' => $this->node->id,
                'name' => 'task-'.$intent->group->id,
                'checkout_path' => '/srv/orbit/apps/planner-demo/task-'.$intent->group->id,
                'branch' => 'task-'.$intent->group->id,
                'status' => 'source_resolved',
            ]);
        }
    };
    $this->planners = new class implements TaskPlannerSpawner
    {
        /** @var list<int> */
        public array $groups = [];

        public bool $refuse = false;

        public function spawnPlanner(TaskGroup $group): ?int
        {
            $this->groups[] = $group->id;

            return $this->refuse ? null : test_agent_thread($group, 'planner-'.$group->id)->id;
        }
    };
    $this->signer = new class implements TaskWorkspaceSigner
    {
        /** @var list<string> */
        public array $messages = [];

        public bool $refuse = false;

        public function commit(AppInstance $instance, string $message): ?string
        {
            $this->messages[] = $message;

            return $this->refuse ? null : str_repeat('a', 40);
        }
    };
    $this->mcp = new class implements TaskPlannerMcp
    {
        /** @var list<int> */
        public array $instances = [];

        public bool $refuse = false;

        public function install(AppInstance $instance): bool
        {
            $this->instances[] = $instance->id;

            return ! $this->refuse;
        }
    };
    app()->instance(TaskPlannerMcp::class, $this->mcp);
    app()->instance(InstanceProvisioning::class, $this->provisioning);
    app()->instance(TaskPlannerSpawner::class, $this->planners);
    app()->instance(TaskWorkspaceSigner::class, $this->signer);
    app()->instance(AgentSpawner::class, new NullAgentSpawner);
    app()->instance(AppInstanceRemover::class, new class implements AppInstanceRemover
    {
        public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
        {
            $instance->delete();

            return new AppInstanceRemoval;
        }
    });
});

/**
 * @param  array<string, mixed>  $extra
 */
function planner_create(mixed $test, array $extra = []): TestResponse
{
    return $test->postJson('/api/v1/task-groups', [
        'app_id' => $test->appRecord->id,
        'title' => 'Planned feature',
        'brief' => 'Shape it with a planner.',
        'plan' => true,
        ...$extra,
    ]);
}

it('provisions the workspace on a self-access Node and starts the planner as the reviewer thread', function (): void {
    $group = planner_create($this)->assertCreated()->json('data');

    expect($group['status'])->toBe('backlog')
        ->and($group['plan'])->toBeTrue()
        ->and($group['taskable_id'])->not->toBeNull()
        ->and($group['reviewer_agent_thread_id'])->not->toBeNull()
        ->and($this->planners->groups)->toBe([$group['id']])
        ->and($this->mcp->instances)->toBe([$group['taskable_id']])
        ->and($this->provisioning->intents)->toHaveCount(1)
        ->and($this->provisioning->intents[0]->selfAccess)->toBeTrue()
        ->and($this->provisioning->intents[0]->visitable)->toBeTrue();
});

it('refuses a planner for a group created in todo', function (): void {
    planner_create($this, ['status' => 'todo', 'tasks' => [['title' => 'One', 'brief' => 'One.', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'One is done.']]]]])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'tasks.plan_requires_backlog');

    expect(TaskGroup::query()->count())->toBe(0)
        ->and($this->provisioning->intents)->toBe([]);
});

it('refuses a planner when the reviewer driver is not T3', function (): void {
    config()->set('orbit.tasks.reviewer_agent_driver', 'pi');

    planner_create($this)
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.planner_driver_unavailable');

    expect(TaskGroup::query()->count())->toBe(0);
});

it('stores no group when no Node can hold the planner', function (): void {
    $this->provisioning->refuse = true;

    planner_create($this)
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.planner_node_unavailable');

    expect(TaskGroup::query()->count())->toBe(0)
        ->and($this->planners->groups)->toBe([]);
});

it('removes the workspace and stores no group when the planner cannot start', function (): void {
    $this->planners->refuse = true;

    planner_create($this)
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.planner_unavailable');

    expect(TaskGroup::query()->count())->toBe(0)
        ->and(AppInstance::query()->count())->toBe(0);
});

it('commits the plan and reuses the workspace when the group moves to todo', function (): void {
    $group = planner_create($this, ['tasks' => [['title' => 'One', 'brief' => 'One.', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'One is done.']]]]])->assertCreated()->json('data');

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'todo', 'title' => 'Planned feature, final'])
        ->assertOk();

    $stored = TaskGroup::query()->findOrFail($group['id']);

    expect($this->signer->messages)->toBe(['Plan: Planned feature, final'])
        ->and($stored->status)->not->toBe(TaskGroupStatus::Backlog)
        ->and($stored->taskable_id)->toBe($group['taskable_id'])
        ->and(AppInstance::query()->count())->toBe(1);
});

it('keeps a planning group in backlog when the plan commit fails', function (): void {
    $group = planner_create($this, ['tasks' => [['title' => 'One', 'brief' => 'One.', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'One is done.']]]]])->assertCreated()->json('data');
    $this->signer->refuse = true;

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'todo'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.commit_failed');

    expect(TaskGroup::query()->findOrFail($group['id'])->status)->toBe(TaskGroupStatus::Backlog);
});

it('commits nothing when a group without a planner moves to todo', function (): void {
    $group = planner_create($this, ['plan' => false, 'tasks' => [['title' => 'One', 'brief' => 'One.', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'One is done.']]]]])->assertCreated()->json('data');

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'todo'])->assertOk();

    expect($this->signer->messages)->toBe([])
        ->and($group['plan'])->toBeFalse();
});

it('keeps the workspace and planner when a planning group moves back to backlog', function (): void {
    $group = planner_create($this, ['tasks' => [['title' => 'One', 'brief' => 'One.', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'One is done.']]]]])->assertCreated()->json('data');
    TaskGroup::query()->whereKey($group['id'])->update(['status' => TaskGroupStatus::Todo]);

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'backlog'])
        ->assertOk()
        ->assertJsonPath('data.taskable_id', $group['taskable_id'])
        ->assertJsonPath('data.reviewer_agent_thread_id', $group['reviewer_agent_thread_id']);
});

it('removes the workspace when a planning group is cancelled', function (): void {
    $group = planner_create($this)->assertCreated()->json('data');

    $this->postJson("/api/v1/task-groups/{$group['id']}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.taskable_id', null);

    expect(AppInstance::query()->count())->toBe(0);
});

it('lets a Node with access to itself manage the planning group its workspace holds', function (): void {
    $group = planner_create($this, ['tasks' => [['title' => 'One', 'brief' => 'One.', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'One is done.']]]]])->assertCreated()->json('data');
    $this->workspaceNode->accessibleNodes()->attach($this->workspaceNode->id);
    $this->withServerVariables(['REMOTE_ADDR' => $this->workspaceNode->wireguard_ip]);

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['brief' => 'Shaped by the planner.'])->assertOk();
    $subtask = $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", ['title' => 'Two', 'brief' => 'Two.'])->assertCreated()->json('data');
    $this->patchJson("/api/v1/task-groups/{$group['id']}/tasks/{$subtask['id']}", ['position' => 1, 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Two is done.']]])->assertOk();
    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['status' => 'todo'])->assertOk();

    expect(TaskGroup::query()->findOrFail($group['id'])->brief)->toBe('Shaped by the planner.')
        ->and($this->signer->messages)->toBe(['Plan: Planned feature']);
});

it('refuses plan changes from a Node without access to the group workspace Node', function (): void {
    $group = planner_create($this)->assertCreated()->json('data');
    $other = Node::query()->create([
        'name' => 'other-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.93', 'wireguard_ip' => '10.44.0.93',
    ]);
    $other->accessibleNodes()->attach($other->id);
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip]);

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['brief' => 'Not mine.'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');
    $this->postJson("/api/v1/task-groups/{$group['id']}/tasks", ['title' => 'Two', 'brief' => 'Two.'])
        ->assertForbidden();
});

it('keeps Gateway access for create and for groups without a workspace', function (): void {
    $group = planner_create($this, ['plan' => false])->assertCreated()->json('data');
    $this->workspaceNode->accessibleNodes()->attach($this->workspaceNode->id);
    $this->withServerVariables(['REMOTE_ADDR' => $this->workspaceNode->wireguard_ip]);

    $this->patchJson("/api/v1/task-groups/{$group['id']}", ['brief' => 'No workspace.'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');
    planner_create($this)->assertForbidden();
});

it('removes the workspace and stores no group when Orbit MCP cannot be given to the planner', function (): void {
    $this->mcp->refuse = true;

    planner_create($this)
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.planner_unavailable');

    expect(TaskGroup::query()->count())->toBe(0)
        ->and(AppInstance::query()->count())->toBe(0)
        ->and($this->planners->groups)->toBe([]);
});
