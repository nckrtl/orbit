<?php

declare(strict_types=1);

use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSessionActor;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadObservation;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;

function complete_group(TaskGroupStatus $status = TaskGroupStatus::Settling): Task
{
    $project = Project::query()->create([
        'name' => 'complete-app',
        'slug' => 'complete-app',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'complete-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.150',
        'wireguard_ip' => '10.44.0.150',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-20',
        'checkout_path' => '/srv/orbit/apps/complete-app/task-20',
        'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Complete me',
        'brief' => 'Remove the workspace after merge.',
        'status' => $status,
        'pr_url' => 'https://github.com/nckrtl/orbit/pull/543',
    ]);
    $group->taskable()->associate($instance);
    $group->save();

    return $group->fresh(['project', 'taskable']) ?? $group;
}

it('removes the shared App instance and marks a settling group completed', function (TaskGroupStatus $status): void {
    app(TaskExtensionState::class)->enable();
    $group = complete_group($status);
    $instanceId = $group->taskable_id;
    $remover = new class implements InstanceRemover
    {
        /** @var list<array{0: int, 1: bool}> */
        public array $calls = [];

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $this->calls[] = [$instance->id, $force];
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    $completed = app(CompleteTaskGroupAction::class)->execute($group);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBeNull()
        ->and($completed->settled_at)->not->toBeNull()
        ->and($remover->calls)->toBe([[$instanceId, true]])
        ->and(Instance::query()->find($instanceId))->toBeNull();
})->with([TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview]);

it('is idempotent for an already completed group and retries a leftover workspace', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = complete_group(TaskGroupStatus::Completed);
    $instanceId = $group->taskable_id;
    $remover = new class implements InstanceRemover
    {
        /** @var list<array{0: int, 1: bool}> */
        public array $calls = [];

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $this->calls[] = [$instance->id, $force];
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    $completed = app(CompleteTaskGroupAction::class)->execute($group);
    $again = app(CompleteTaskGroupAction::class)->execute($completed);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($again->status)->toBe(TaskGroupStatus::Completed)
        ->and($remover->calls)->toBe([[$instanceId, true]])
        ->and($again->taskable_id)->toBeNull()
        ->and(Instance::query()->find($instanceId))->toBeNull();
});

it('marks the group completed and reports the removal failure when the workspace cannot be removed', function (): void {
    app(TaskExtensionState::class)->enable();
    $remover = new class implements InstanceRemover
    {
        public bool $fail = true;

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            if ($this->fail) {
                throw new ResourceOperationException('instance.force_failed', 'The Node is unreachable.', 409);
            }
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);
    $group = complete_group();
    $instanceId = $group->taskable_id;

    $completed = app(CompleteTaskGroupAction::class)->execute($group);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBe($instanceId)
        ->and($completed->assistance_requested)->toBeFalse()
        ->and($completed->assistance_reason)->toBe('Workspace removal failed: The Node is unreachable.')
        ->and(Instance::query()->find($instanceId))->not->toBeNull();

    $remover->fail = false;
    $retried = app(CompleteTaskGroupAction::class)->execute($completed);

    expect($retried->status)->toBe(TaskGroupStatus::Completed)
        ->and($retried->taskable_id)->toBeNull()
        ->and($retried->assistance_requested)->toBeFalse()
        ->and(Instance::query()->find($instanceId))->toBeNull();
});

it('returns 409 tasks.not_settling when the group is still running', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = complete_group(TaskGroupStatus::Running);

    expect(fn () => app(CompleteTaskGroupAction::class)->execute($group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('tasks.not_settling')
                ->and($exception->status)->toBe(409);
        });
});

it('returns 409 extension.disabled while the extension is off', function (): void {
    $group = complete_group();

    expect(fn () => app(CompleteTaskGroupAction::class)->execute($group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('extension.disabled')
                ->and($exception->status)->toBe(409);
        });
});

/** Authorizes only the watched PR, not the separately published PR. */
function complete_ended_read(?string $state): void
{
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/nckrtl/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_complete'], 201),
        'https://api.github.com/repos/nckrtl/orbit/pulls/544' => $state === null
            ? Http::response([], 503)
            : Http::response(['state' => $state === 'open' ? 'open' : 'closed', 'merged' => $state === 'merged', 'merged_at' => $state === 'merged' ? '2026-10-08T10:00:00Z' : null]),
    ]);
}

it('complete ended cancels open subtasks through the API and removes the workspace', function (TaskGroupStatus $status, string $state): void {
    app(TaskExtensionState::class)->enable();
    complete_ended_read($state);
    $group = complete_group($status);
    $group->update(['watched_pr_url' => 'https://github.com/nckrtl/orbit/pull/544', 'watched_pr_state' => 'open', 'assistance_requested' => true, 'assistance_reason' => 'Watched pull request ended: prior notice.']);
    $instance = $group->taskable;
    $instanceId = $instance->id;
    $this->markAsGateway($instance->node);
    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);
    $tasks = collect([TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing, TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])
        ->map(fn (TaskStatus $status, int $position): Task => Task::query()->create([
            'parent_id' => $group->id, 'position' => $position + 1, 'title' => $status->value, 'brief' => 'Keep terminal work.',
            'status' => $status, 'assistance_requested' => true, 'assistance_reason' => 'Previous reason.',
        ]));
    foreach ([$tasks[1], $tasks[2]] as $task) {
        $role = $task->status === TaskStatus::Running ? 'implementer' : 'reviewer';
        $thread = AgentThread::query()->create([
            'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $instance->node_id,
            'driver' => 'fake', 'runtime_key' => 'node:'.$instance->node_id, 'external_id' => $role, 'role' => $role,
        ]);
        if ($role === 'implementer') {
            $task->update(['implementer_agent_thread_id' => $thread->id]);
        } else {
            $group->update(['reviewer_agent_thread_id' => $thread->id]);
        }
    }
    $check = TaskCheck::query()->create([
        'task_id' => $tasks[1]->id, 'kind' => TaskCheckKind::Baseline, 'status' => TaskCheckStatus::Running,
        'pid' => 4100, 'process_started' => 'Wed Sep 23 12:00:00 2026', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);
    $driver = new FakeAgentDriver('fake');
    $driver->supportsInterruption = true;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    $level = DB::transactionLevel();
    $remover = new class($group->id, $level) implements InstanceRemover
    {
        public function __construct(private int $groupId, private int $level) {}

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            expect($force)->toBeTrue()
                ->and(DB::transactionLevel())->toBe($this->level)
                ->and(Task::topLevel()->findOrFail($this->groupId)->status)->toBe(TaskGroupStatus::Completed)
                ->and(Task::query()->where('parent_id', $this->groupId)->whereIn('status', ['todo', 'running', 'reviewing'])->exists())->toBeFalse();
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    $this->postJson("/api/v1/task-groups/{$group->id}/complete")
        ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.assistance_requested', false)->assertJsonPath('data.taskable_id', null);

    expect($group->fresh()->watched_pr_completion)->toBe($state)
        ->and($group->fresh()->pr_url)->toBe('https://github.com/nckrtl/orbit/pull/543')
        ->and($group->fresh()->assistance_requested)->toBeFalse()
        ->and($group->fresh()->settled_at)->not->toBeNull()
        ->and($group->tasks()->orderBy('position')->get()->map(fn (Task $task): TaskStatus => $task->status)->all())->toBe([TaskStatus::Cancelled, TaskStatus::Cancelled, TaskStatus::Cancelled, TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])
        ->and($check->fresh()->status)->toBe(TaskCheckStatus::Cancelled)
        ->and($check->fresh()->finished_at)->not->toBeNull()
        ->and($checks->cancels)->toBe(1)
        ->and($checks->starts)->toBe(0)
        ->and($driver->calls)->toBe([['operation' => 'interrupt', 'thread' => 'implementer'], ['operation' => 'interrupt', 'thread' => 'reviewer']])
        ->and($driver->interruptTransactionLevels)->toBe([$level, $level])
        ->and($checks->cancelTransactionLevels)->toBe([$level])
        ->and(Instance::query()->find($instanceId))->toBeNull();
    Http::assertSentCount(3);
})->with([TaskGroupStatus::Running, TaskGroupStatus::Reviewing])->with(['merged', 'closed']);

it('complete ended still refuses other running or reviewing groups', function (TaskGroupStatus $status, ?string $state, ?string $url): void {
    app(TaskExtensionState::class)->enable();
    complete_ended_read($state);
    $group = complete_group($status);
    $group->update(['watched_pr_url' => $url, 'watched_pr_state' => 'merged']);
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Open', 'brief' => 'Still open.', 'status' => TaskStatus::Todo]);
    $this->markAsGateway($group->taskable->node);
    $this->withServerVariables(['REMOTE_ADDR' => $group->taskable->node->wireguard_ip]);

    $this->postJson("/api/v1/task-groups/{$group->id}/complete")
        ->assertStatus(409)->assertJsonPath('error.code', 'tasks.not_settling');

    expect($group->fresh()->status)->toBe($status)
        ->and($task->fresh()->status)->toBe(TaskStatus::Todo)
        ->and(Instance::query()->find($group->taskable_id))->not->toBeNull();
})->with([TaskGroupStatus::Running, TaskGroupStatus::Reviewing])->with([
    'missing URL' => ['merged', null],
    'open' => ['open', 'https://github.com/nckrtl/orbit/pull/544'],
    'unreadable' => [null, 'https://github.com/nckrtl/orbit/pull/544'],
    'wrong repository' => ['merged', 'https://github.com/another/orbit/pull/544'],
]);

it('complete ended resumes a committed authorization without GitHub after a stop or transaction failure', function (TaskGroupStatus $status, string $failure): void {
    app(TaskExtensionState::class)->enable();
    complete_ended_read('merged');
    $group = complete_group($status);
    $group->update(['watched_pr_url' => 'https://github.com/nckrtl/orbit/pull/544']);
    $task = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Open', 'brief' => 'Cancel atomically.',
        'status' => TaskStatus::Running,
    ]);
    $thread = AgentThread::query()->create([
        'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $group->taskable->node_id,
        'driver' => 'fake', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'recover', 'role' => 'implementer',
    ]);
    $task->update(['implementer_agent_thread_id' => $thread->id]);
    $check = TaskCheck::query()->create([
        'task_id' => $task->id, 'kind' => TaskCheckKind::Baseline, 'status' => TaskCheckStatus::Running,
        'pid' => 4100, 'process_started' => 'Wed Sep 23 12:00:00 2026', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);
    $driver = new FakeAgentDriver('fake');
    $driver->supportsInterruption = true;
    $driver->failNextInterrupt = $failure === 'interrupt';
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $checks = new FakeTaskCheckRunner;
    $checks->failNextCancel = $failure === 'check';
    app()->instance(TaskCheckRunner::class, $checks);
    $receipts = new FakeTaskTurnReceipts;
    app()->instance(TaskTurnReceipts::class, $receipts);
    $spawner = new class implements AgentSpawner
    {
        public int $starts = 0;

        public function spawnReviewer(Task $task): ?int
        {
            $this->starts++;

            return null;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->starts++;

            return null;
        }

        public function requestReview(Task $task): void
        {
            $this->starts++;
        }
    };
    app()->instance(AgentSpawner::class, $spawner);
    $stale = $task->fresh(['parent']);
    $driver->duringInterrupt = static function () use ($stale): void {
        // An already-running tick holds this pre-authorization model and tries to start review.
        app(TaskScheduler::class)->settleImplementer($stale);
    };
    $failUpdate = $failure === 'transaction';
    Task::updating(static function (Task $updating) use ($group, &$failUpdate): void {
        if ($failUpdate && $updating->id === $group->id && $updating->status === TaskGroupStatus::Completed) {
            $failUpdate = false;
            throw new RuntimeException('Injected parent update failure.');
        }
    });
    $instanceId = $group->taskable_id;
    app()->instance(InstanceRemover::class, new class implements InstanceRemover
    {
        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $instance->delete();

            return new InstanceRemoval;
        }
    });

    expect(fn () => app(CompleteTaskGroupAction::class)->execute($group))->toThrow($failure === 'transaction' ? RuntimeException::class : ResourceOperationException::class);

    expect($group->fresh()->watched_pr_completion)->toBe('merged')
        ->and($group->fresh()->status)->toBe($status)
        ->and($task->fresh()->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()->settled_at)->toBeNull()
        ->and($check->fresh()->status)->toBe(TaskCheckStatus::Running)
        ->and(Instance::query()->find($instanceId))->not->toBeNull()
        ->and($spawner->starts)->toBe(0)
        ->and($receipts->prepared)->toBe([])
        ->and($checks->starts)->toBe(0);
    Http::assertSentCount(3);
    Http::fake();

    app(TaskScheduler::class)->tick();

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Completed)
        ->and($group->fresh()->taskable_id)->toBeNull()
        ->and($task->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($check->fresh()->status)->toBe(TaskCheckStatus::Cancelled)
        ->and(Instance::query()->find($instanceId))->toBeNull();
    Http::assertNothingSent();
})->with([TaskGroupStatus::Running, TaskGroupStatus::Reviewing])->with(['interrupt', 'check', 'transaction']);

it('complete ended holds stale transitions and start or send boundaries during interrupt without an assistance reason', function (TaskGroupStatus $status, bool $fail): void {
    app(TaskExtensionState::class)->enable();
    complete_ended_read('merged');
    $group = complete_group($status);
    $group->update(['watched_pr_url' => 'https://github.com/nckrtl/orbit/pull/544', 'implementer_agent_driver' => 'fake', 'reviewer_agent_driver' => 'fake']);
    $taskStatus = $status === TaskGroupStatus::Running ? TaskStatus::Running : TaskStatus::Reviewing;
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Acting', 'brief' => 'Stop.', 'status' => $taskStatus]);
    $todo = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Queued', 'brief' => 'Never start.', 'status' => TaskStatus::Todo]);
    $role = $taskStatus === TaskStatus::Running ? TaskThreadRole::Implementer : TaskThreadRole::Reviewer;
    $thread = AgentThread::query()->create([
        'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $group->taskable->node_id,
        'driver' => 'fake', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'acting', 'role' => $role->value,
    ]);
    $role === TaskThreadRole::Implementer
        ? $task->update(['implementer_agent_thread_id' => $thread->id])
        : $group->update(['reviewer_agent_thread_id' => $thread->id]);
    $check = TaskCheck::query()->create([
        'task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running,
        'pid' => 4100, 'process_started' => 'Wed Sep 23 12:00:00 2026', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);
    $driver = new FakeAgentDriver('fake');
    $driver->supportsInterruption = true;
    $driver->failNextInterrupt = $fail;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    $receipts = new FakeTaskTurnReceipts;
    app()->instance(TaskTurnReceipts::class, $receipts);
    $instanceId = $group->taskable_id;
    app()->instance(InstanceRemover::class, new class implements InstanceRemover
    {
        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $instance->delete();

            return new InstanceRemoval;
        }
    });
    $stale = $task->fresh(['parent']);
    $queued = $todo->fresh(['parent']);
    $observed = new TaskThreadObservation($thread->id, $role, 'done', true, null, null, null, null, false, null, null);
    $driver->duringInterrupt = static function () use ($stale, $queued, $observed, $status): void {
        $scheduler = app(TaskScheduler::class);
        $scheduler->settleImplementer($stale);
        $scheduler->acceptReview($stale);
        $scheduler->startTask($queued);
        expect($stale->parent->fresh()->status)->toBe($status)
            ->and($stale->fresh()->status)->toBe($stale->status)
            ->and($queued->fresh()->status)->toBe(TaskStatus::Todo);
        // These are real admission boundaries, not a fake spawner that ignores the parent.
        $spawner = app(TaskAgentSpawner::class);
        expect($spawner->reserveReviewer($stale))->toBeNull()
            ->and($spawner->spawnReviewer($stale))->toBeNull()
            ->and($spawner->reserveImplementer($queued))->toBeNull()
            ->and($spawner->spawnImplementer($queued))->toBeNull();
        $spawner->requestReview($stale);
        $actor = app(TaskSessionActor::class);
        $actor->remindRubric($stale->parent, $observed, 'Start another turn.');
        $actor->relayReviewBody($stale->parent, $observed, 'Findings.');
        $actor->resumeInterruptedTurn($stale->parent, $observed, 'Restart.', 'restart-key');
    };

    if ($fail) {
        expect(fn () => app(CompleteTaskGroupAction::class)->execute($group))->toThrow(ResourceOperationException::class);
        expect($group->fresh()->status)->toBe($status)
            ->and($group->fresh()->watched_pr_completion)->toBe('merged')
            ->and($group->fresh()->assistance_reason)->toBeNull()
            ->and($task->fresh()->status)->toBe($taskStatus)
            ->and($check->fresh()->status)->toBe(TaskCheckStatus::Running)
            ->and(Instance::query()->find($instanceId))->not->toBeNull();
        Http::fake();
        app(TaskScheduler::class)->tick();
        Http::assertNothingSent();
    } else {
        app(CompleteTaskGroupAction::class)->execute($group);
        Http::assertSentCount(3);
    }

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Completed)
        ->and($task->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($todo->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($check->fresh()->status)->toBe(TaskCheckStatus::Cancelled)
        ->and($checks->starts)->toBe(0)
        ->and($receipts->prepared)->toBe([])
        ->and(AgentThread::query()->where('task_group_id', $group->id)->count())->toBe(1)
        ->and(array_column($driver->calls, 'operation'))->toBe($fail ? ['interrupt', 'interrupt'] : ['interrupt'])
        ->and(Instance::query()->find($instanceId))->toBeNull();
})->with([TaskGroupStatus::Running, TaskGroupStatus::Reviewing])->with([false, true]);

it('complete ended revalidates and stops a replacement reviewer and check before workspace removal', function (bool $fail): void {
    app(TaskExtensionState::class)->enable();
    complete_ended_read('merged');
    $group = complete_group(TaskGroupStatus::Running);
    $group->update(['watched_pr_url' => 'https://github.com/nckrtl/orbit/pull/544']);
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Acting', 'brief' => 'Stop replacement.', 'status' => TaskStatus::Running]);
    $thread = AgentThread::query()->create([
        'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $group->taskable->node_id,
        'driver' => 'fake', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'implementer', 'role' => 'implementer',
    ]);
    $task->update(['implementer_agent_thread_id' => $thread->id]);
    $previousReviewer = AgentThread::query()->create([
        'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $group->taskable->node_id,
        'driver' => 'fake', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'previous-reviewer', 'role' => 'reviewer',
    ]);
    $group->update(['reviewer_agent_thread_id' => $previousReviewer->id]);
    $driver = new FakeAgentDriver('fake');
    $driver->supportsInterruption = true;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    $driver->duringInterrupt = static function () use ($group, $task, $driver, $fail): void {
        $driver->duringInterrupt = $fail ? static function () use ($driver): void {
            $driver->duringInterrupt = null;
            $driver->failNextInterrupt = true;
        } : null;
        // Inject a changed stop snapshot independently of guarded admission to exercise fail-closed revalidation.
        $replacement = AgentThread::query()->create([
            'task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $group->taskable->node_id,
            'driver' => 'fake', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'replacement-reviewer', 'role' => 'reviewer',
        ]);
        $group->fresh()->update(['status' => TaskGroupStatus::Reviewing, 'reviewer_agent_thread_id' => $replacement->id]);
        $task->update(['status' => TaskStatus::Reviewing]);
        TaskCheck::query()->create([
            'task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running,
            'pid' => 4200, 'process_started' => 'Wed Sep 23 12:00:01 2026', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
        ]);
    };
    $instanceId = $group->taskable_id;
    $remover = new class($driver, $checks, $fail) implements InstanceRemover
    {
        public function __construct(private FakeAgentDriver $driver, private FakeTaskCheckRunner $checks, private bool $fail) {}

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            expect(array_column($this->driver->calls, 'thread'))->toBe($this->fail ? ['implementer', 'replacement-reviewer', 'replacement-reviewer'] : ['implementer', 'replacement-reviewer'])
                ->and($this->checks->cancels)->toBe(2);
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    if ($fail) {
        expect(fn () => app(CompleteTaskGroupAction::class)->execute($group))->toThrow(ResourceOperationException::class);
        expect($group->fresh()->status)->toBe(TaskGroupStatus::Reviewing)
            ->and($task->fresh()->status)->toBe(TaskStatus::Reviewing)
            ->and($task->checks()->firstOrFail()->status)->toBe(TaskCheckStatus::Running)
            ->and(Instance::query()->find($instanceId))->not->toBeNull();
        Http::fake();
    }
    $completed = app(CompleteTaskGroupAction::class)->execute($group);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBeNull()
        ->and($completed->assistance_requested)->toBeFalse()
        ->and($task->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($task->checks()->firstOrFail()->status)->toBe(TaskCheckStatus::Cancelled)
        ->and(Instance::query()->find($instanceId))->toBeNull();
    if ($fail) {
        Http::assertNothingSent();
    }
})->with([false, true]);

it('complete ended refuses an interrupted baseline start and retries without GitHub once its identity is recorded', function (int $pid, string $started, string $resume): void {
    app(TaskExtensionState::class)->enable();
    complete_ended_read('merged');
    $group = complete_group(TaskGroupStatus::Running);
    $group->update(['watched_pr_url' => 'https://github.com/nckrtl/orbit/pull/544']);
    $instanceId = $group->taskable_id;
    $this->markAsGateway($group->taskable->node);
    $this->withServerVariables(['REMOTE_ADDR' => $group->taskable->node->wireguard_ip]);
    $running = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Baseline', 'brief' => 'A remote check may still run.', 'status' => TaskStatus::Running]);
    $todo = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Queued', 'brief' => 'Keep open until completion commits.', 'status' => TaskStatus::Todo]);
    $check = TaskCheck::query()->create([
        'task_id' => $running->id, 'kind' => TaskCheckKind::Baseline, 'status' => TaskCheckStatus::Running,
        'pid' => $pid, 'process_started' => $started, 'head_before' => '', 'tree_before' => '',
        'started_at' => now()->subSeconds(TaskScheduler::BASELINE_START_LIMIT_SECONDS + 1),
    ]);
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    $remover = new class implements InstanceRemover
    {
        public int $calls = 0;

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $this->calls++;
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    $this->postJson("/api/v1/task-groups/{$group->id}/complete")
        ->assertStatus(502)->assertJsonPath('error.code', 'tasks.subtask_interrupt_failed');

    expect($group->fresh()->watched_pr_completion)->toBe('merged')
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($running->fresh()->status)->toBe(TaskStatus::Running)
        ->and($todo->fresh()->status)->toBe(TaskStatus::Todo)
        ->and($check->fresh()->status)->toBe(TaskCheckStatus::Running)
        ->and($check->fresh()->finished_at)->toBeNull()
        ->and($checks->cancels)->toBe(0)
        ->and($checks->starts)->toBe(0)
        ->and($remover->calls)->toBe(0)
        ->and(Instance::query()->find($instanceId))->not->toBeNull();
    Http::assertSentCount(3);
    Http::fake();

    // An unreconciled retry must remain fail-closed and use the committed authorization.
    $this->postJson("/api/v1/task-groups/{$group->id}/complete")
        ->assertStatus(502)->assertJsonPath('error.code', 'tasks.subtask_interrupt_failed');
    expect($checks->cancels)->toBe(0)
        ->and($remover->calls)->toBe(0)
        ->and($check->fresh()->status)->toBe(TaskCheckStatus::Running);
    Http::assertNothingSent();

    // Represent reconciliation of the remote start result, not a new check or a new GitHub read.
    $check->update(['pid' => 4100, 'process_started' => 'Wed Sep 23 12:00:00 2026']);
    if ($resume === 'manual') {
        $this->postJson("/api/v1/task-groups/{$group->id}/complete")
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.taskable_id', null);
    } else {
        app(TaskScheduler::class)->tick();
    }

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Completed)
        ->and($group->fresh()->taskable_id)->toBeNull()
        ->and($running->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($todo->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($check->fresh()->status)->toBe(TaskCheckStatus::Cancelled)
        ->and($check->fresh()->finished_at)->not->toBeNull()
        ->and($checks->cancels)->toBe(1)
        ->and($checks->starts)->toBe(0)
        ->and($remover->calls)->toBe(1)
        ->and(Instance::query()->find($instanceId))->toBeNull();
    Http::assertNothingSent();
})->with([
    'unrecorded start result' => [0, ''],
    'missing PID' => [0, 'Wed Sep 23 12:00:00 2026'],
    'missing start time' => [4100, ''],
])->with(['manual', 'tick']);

it('complete ended retries workspace removal after the completion commit without GitHub', function (): void {
    app(TaskExtensionState::class)->enable();
    complete_ended_read('closed');
    $group = complete_group(TaskGroupStatus::Running);
    $group->update(['watched_pr_url' => 'https://github.com/nckrtl/orbit/pull/544']);
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Open', 'brief' => 'Cancel.', 'status' => TaskStatus::Todo]);
    $remover = new class implements InstanceRemover
    {
        public bool $fail = true;

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            if ($this->fail) {
                throw new RuntimeException('Stopped before workspace removal.');
            }
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);
    // Propagate the injected crash rather than handling it as a manual cleanup failure.
    expect(fn () => app(CompleteTaskGroupAction::class)->execute($group, finishWhenRemovalFails: false))->toThrow(RuntimeException::class);

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Completed)
        ->and($group->fresh()->watched_pr_completion)->toBe('closed')
        ->and($task->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($group->fresh()->taskable_id)->not->toBeNull();
    Http::assertSentCount(3);
    Http::fake();
    $remover->fail = false;

    $completed = app(CompleteTaskGroupAction::class)->execute($group);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBeNull()
        ->and($completed->assistance_requested)->toBeFalse()
        ->and($task->fresh()->status)->toBe(TaskStatus::Cancelled);
    Http::assertNothingSent();
});
