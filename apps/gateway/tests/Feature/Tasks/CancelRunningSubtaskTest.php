<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\NullCoderSettleNotifier;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskRunReceipts;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Models\AgentThread;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskGroup;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskRunReceipts;

/**
 * A running group on an Instance whose first subtask is in its baseline check and whose second waits.
 *
 * @return array{TaskGroup, Task, Task, TaskCheck, FakeTaskCheckRunner, object, Node}
 */
function cancel_subtask_in_baseline(int $suffix): array
{
    $gateway = Node::query()->create([
        'name' => 'cancel-baseline-gateway-'.$suffix,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.$suffix,
        'wireguard_ip' => '10.44.1.'.$suffix,
    ]);
    app(TaskExtensionState::class)->enable();
    $app = OrbitApp::query()->create([
        'name' => 'Cancel baseline',
        'slug' => 'cancel-baseline-'.$suffix,
        'repository_url' => 'git@example.test:cancel-baseline.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'cancel-baseline-node-'.$suffix,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.3.'.$suffix,
        'wireguard_ip' => '10.44.2.'.$suffix,
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-workspace',
        'checkout_path' => '/srv/orbit/apps/cancel-baseline/task-workspace',
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Cancel during baseline',
        'brief' => 'Stop the baseline check.',
        'status' => TaskGroupStatus::Running,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $running = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'Checking subtask',
        'brief' => 'Its baseline check runs.',
        'status' => TaskStatus::Running,
    ]);
    $next = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 2,
        'title' => 'Next subtask',
        'brief' => 'Needs its own baseline.',
        'status' => TaskStatus::Todo,
    ]);
    $check = TaskCheck::query()->create([
        'task_id' => $running->id,
        'kind' => TaskCheckKind::Baseline,
        'status' => TaskCheckStatus::Running,
        'pid' => 4100,
        'process_started' => 'Wed Sep 23 12:00:00 2026',
        'head_before' => str_repeat('a', 40),
        'tree_before' => str_repeat('b', 40),
        'started_at' => now(),
    ]);
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);
    app()->instance(TaskWorkspaceStateReader::class, new class implements TaskWorkspaceStateReader
    {
        public function headCommit(AppInstance $instance): ?string
        {
            return 'baseline-head';
        }

        public function currentBranch(AppInstance $instance): ?string
        {
            return 'task-test';
        }

        public function definesComposerCheckScript(AppInstance $instance): bool
        {
            return true;
        }
    });
    $spawner = new class implements AgentSpawner
    {
        public int $implementers = 0;

        public function spawnReviewer(Task $task): ?int
        {
            return null;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->implementers++;

            return null;
        }

        public function requestReview(Task $task): void {}
    };
    app()->instance(AgentSpawner::class, $spawner);

    return [$group, $running, $next, $check, $checks, $spawner, $gateway];
}

it('cancel running subtask preserves its group and Instance', function (): void {
    $gateway = Node::query()->create([
        'name' => 'cancel-subtask-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.97',
        'wireguard_ip' => '10.44.0.97',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $app = OrbitApp::query()->create([
        'name' => 'Cancel subtask',
        'slug' => 'cancel-subtask',
        'repository_url' => 'git@example.test:cancel-subtask.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'cancel-subtask-instance',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.98',
        'wireguard_ip' => '10.44.0.98',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-workspace',
        'checkout_path' => '/srv/orbit/apps/cancel-subtask/task-workspace',
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Cancel one subtask',
        'brief' => 'Keep the group workspace.',
        'status' => TaskGroupStatus::Running,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $running = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'Running subtask',
        'brief' => 'Stop only this implementer.',
        'status' => TaskStatus::Running,
    ]);
    $sibling = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 2,
        'title' => 'Sibling subtask',
        'brief' => 'Keep this work queued.',
        'status' => TaskStatus::Todo,
    ]);
    $completed = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 3,
        'title' => 'Completed subtask',
        'brief' => 'Keep this completed work.',
        'status' => TaskStatus::Completed,
        'settled_at' => now(),
    ]);
    $implementer = AgentThread::query()->create([
        'task_group_id' => $group->id,
        'task_id' => $running->id,
        'node_id' => $node->id,
        'driver' => 'fake',
        'runtime_key' => 'node:'.$node->id,
        'external_id' => 'implementer-session',
        'role' => 'implementer',
    ]);
    AgentThread::query()->create([
        'task_group_id' => $group->id,
        'node_id' => $node->id,
        'driver' => 'fake',
        'runtime_key' => 'node:'.$node->id,
        'external_id' => 'reviewer-session',
        'role' => 'reviewer',
    ]);
    $driver = new FakeAgentDriver('fake');
    $driver->supportsInterruption = true;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(TaskRunReceipts::class, new FakeTaskRunReceipts);
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);
    app()->instance(TaskWorkspaceStateReader::class, new class implements TaskWorkspaceStateReader
    {
        public function headCommit(AppInstance $instance): ?string
        {
            return 'test-head';
        }

        public function currentBranch(AppInstance $instance): ?string
        {
            return 'task-test';
        }

        public function definesComposerCheckScript(AppInstance $instance): bool
        {
            return false;
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
            $group = $task->taskGroup;
            $instance = $group->taskable;
            if (! $instance instanceof AppInstance) {
                return null;
            }

            return AgentThread::query()->create([
                'task_group_id' => $group->id,
                'task_id' => $task->id,
                'node_id' => $instance->node_id,
                'driver' => 'fake',
                'runtime_key' => 'node:'.$instance->node_id,
                'external_id' => 'next-implementer-'.$task->id,
                'role' => 'implementer',
            ])->id;
        }

        public function requestReview(Task $task): void {}
    });

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$running->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('meta.request_id', fn (mixed $id): bool => is_string($id));

    expect($running->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($running->fresh()->settled_at)->not->toBeNull()
        ->and($sibling->fresh()->status)->toBe(TaskStatus::Running)
        ->and($sibling->fresh()->implementer_agent_thread_id)->not->toBeNull()
        ->and($sibling->fresh()->subtask_start_commit)->toBe('test-head')
        ->and($completed->fresh()->status)->toBe(TaskStatus::Completed)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()->taskable_id)->toBe($instance->id)
        ->and(AppInstance::query()->find($instance->id))->not->toBeNull()
        ->and($driver->calls)->toBe([['operation' => 'interrupt', 'thread' => 'implementer-session']]);
});

it('cancels a running subtask through the generated MCP tool', function (): void {
    $gateway = Node::query()->create([
        'name' => 'cancel-subtask-mcp-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.100',
        'wireguard_ip' => '10.44.0.100',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $app = OrbitApp::query()->create([
        'name' => 'Cancel via MCP',
        'slug' => 'cancel-via-mcp',
        'repository_url' => 'git@example.test:cancel-via-mcp.git',
        'default_branch' => 'main',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Cancel via MCP',
        'brief' => 'Use the generated tool.',
        'status' => TaskGroupStatus::Running,
    ]);
    $task = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'Running subtask',
        'brief' => 'Cancel using MCP.',
        'status' => TaskStatus::Running,
    ]);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'tasks-subtask-cancel',
            'arguments' => ['group' => $group->id, 'task' => $task->id],
        ],
    ])->assertOk();
    $payload = json_decode($response->json('result.content.0.text'), true);

    expect($response->json('result.isError'))->toBeFalse()
        ->and($payload['data']['status'])->toBe('cancelled')
        ->and($task->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Settling);
});

it('retries after an interrupt failure and settles when cancelling the last subtask', function (): void {
    $gateway = Node::query()->create([
        'name' => 'cancel-subtask-retry-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.101',
        'wireguard_ip' => '10.44.0.101',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);
    $app = OrbitApp::query()->create([
        'name' => 'Cancel retry',
        'slug' => 'cancel-retry',
        'repository_url' => 'git@example.test:cancel-retry.git',
        'default_branch' => 'main',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Cancel final subtask',
        'brief' => 'Retry and settle.',
        'status' => TaskGroupStatus::Running,
    ]);
    $task = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'Final subtask',
        'brief' => 'No successor.',
        'status' => TaskStatus::Running,
    ]);
    AgentThread::query()->create([
        'task_group_id' => $group->id,
        'task_id' => $task->id,
        'driver' => 'fake',
        'runtime_key' => 'retry-runtime',
        'external_id' => 'retry-session',
        'role' => 'implementer',
    ]);
    $driver = new FakeAgentDriver('fake');
    $driver->supportsInterruption = true;
    $driver->failNextInterrupt = true;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/cancel")
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'tasks.subtask_interrupt_failed');

    expect($task->fresh()->status)->toBe(TaskStatus::Running)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Running);

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Settling)
        ->and($driver->calls)->toBe([
            ['operation' => 'interrupt', 'thread' => 'retry-session'],
            ['operation' => 'interrupt', 'thread' => 'retry-session'],
        ]);
});

it('returns a conflict when the subtask is not running', function (): void {
    $gateway = Node::query()->create([
        'name' => 'cancel-not-running-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.99',
        'wireguard_ip' => '10.44.0.99',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $app = OrbitApp::query()->create([
        'name' => 'Cancel idle subtask',
        'slug' => 'cancel-idle-subtask',
        'repository_url' => 'git@example.test:cancel-idle-subtask.git',
        'default_branch' => 'main',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Not running',
        'brief' => 'Reject cancellation.',
        'status' => TaskGroupStatus::Running,
    ]);
    $task = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'Queued subtask',
        'brief' => 'Still queued.',
        'status' => TaskStatus::Todo,
    ]);

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/cancel")
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.subtask_not_running');

    expect($task->fresh()->status)->toBe(TaskStatus::Todo)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Running);
});

it('stops the baseline check and runs the next subtask through its own baseline', function (): void {
    [$group, $running, $next, $check, $checks, $spawner, $gateway] = cancel_subtask_in_baseline(110);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$running->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $nextCheck = TaskCheck::query()->where('task_id', $next->id)->sole();
    expect($checks->cancels)->toBe(1)
        ->and($check->fresh()->status)->toBe(TaskCheckStatus::Cancelled)
        ->and($next->fresh()->status)->toBe(TaskStatus::Running)
        ->and($next->fresh()->subtask_start_commit)->toBe('baseline-head')
        ->and($next->fresh()->implementer_agent_thread_id)->toBeNull()
        ->and($spawner->implementers)->toBe(0)
        ->and($checks->starts)->toBe(1)
        ->and($nextCheck->kind)->toBe(TaskCheckKind::Baseline)
        ->and($nextCheck->status)->toBe(TaskCheckStatus::Running)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Running);
});

it('leaves the subtask and its baseline check running when the check cannot be stopped', function (): void {
    [$group, $running, $next, $check, $checks, $spawner, $gateway] = cancel_subtask_in_baseline(111);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $checks->failNextCancel = true;

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$running->id}/cancel")
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'tasks.subtask_interrupt_failed');

    expect($running->fresh()->status)->toBe(TaskStatus::Running)
        ->and($check->fresh()->status)->toBe(TaskCheckStatus::Running)
        ->and($next->fresh()->status)->toBe(TaskStatus::Todo)
        ->and($checks->starts)->toBe(0);
});

it('sends no interrupt when the subtask stopped running before the lock', function (): void {
    [$group, $running, $next, $check, $checks, $spawner, $gateway] = cancel_subtask_in_baseline(112);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    AgentThread::query()->create([
        'task_group_id' => $group->id,
        'task_id' => $running->id,
        'driver' => 'fake',
        'runtime_key' => 'handed-off-runtime',
        'external_id' => 'handed-off-session',
        'role' => 'implementer',
    ]);
    $driver = new FakeAgentDriver('fake');
    $driver->supportsInterruption = true;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $running->update(['status' => TaskStatus::Reviewing]);

    $this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$running->id}/cancel")
        ->assertConflict()
        ->assertJsonPath('error.code', 'tasks.subtask_not_running');

    expect($driver->calls)->toBe([])
        ->and($running->fresh()->status)->toBe(TaskStatus::Reviewing);
});
