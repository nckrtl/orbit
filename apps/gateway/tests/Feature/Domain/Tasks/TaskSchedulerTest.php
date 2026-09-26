<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceSourceLifecycle;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\LocalTaskSettleMetricsCollector;
use App\Domain\Tasks\NullCoderSettleNotifier;
use App\Domain\Tasks\NullTaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskBriefCoverage;
use App\Domain\Tasks\TaskCeilings;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskConcurrencyGuard;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupMetricsRefresher;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskRunInstructions;
use App\Domain\Tasks\TaskRunPullRequest;
use App\Domain\Tasks\TaskRunReceiptException;
use App\Domain\Tasks\TaskRunReceipts;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSequenceException;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskSettleMetrics;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Infrastructure\Tasks\T3\NullT3ThreadReader;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Infrastructure\Tasks\T3\T3ThreadReader;
use App\Models\AgentThread;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskGroup;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskRunReceipts;

use function Pest\Laravel\mock;

function scheduler_app(string $slug): OrbitApp
{
    return OrbitApp::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
        'default_branch' => 'main',
    ]);
}

function scheduler_node(string $name, string $ip): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => $ip,
        'wireguard_ip' => $ip,
    ]);
}

function scheduler_instance(OrbitApp $app, Node $node, string $name): AppInstance
{
    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/tmp/tasks-{$app->slug}-{$name}",
        'status' => 'reserved',
    ]);
}

function queued_group(OrbitApp $app, string $title, ?AppInstance $instance = null): TaskGroup
{
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => $title,
        'brief' => "{$title} brief",
        'status' => TaskGroupStatus::Todo,
    ]);

    Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => "{$title} first",
        'brief' => 'First subtask',
        'status' => TaskStatus::Todo,
    ]);

    if ($instance instanceof AppInstance) {
        $group->taskable()->associate($instance);
        $group->save();
    }

    return $group->fresh(['tasks', 'taskable']) ?? $group;
}

function scheduler_pending_task(TaskGroup $group, int $position, string $title): Task
{
    return Task::query()->create([
        'task_group_id' => $group->id,
        'position' => $position,
        'title' => $title,
        'brief' => "{$title} subtask",
        'status' => TaskStatus::Todo,
    ]);
}

function scheduler_recording_spawner(): AgentSpawner
{
    return new class implements AgentSpawner
    {
        /** @var list<string> */
        public array $events = [];

        public function spawnReviewer(Task $task): ?int
        {
            $group = $task->taskGroup;

            $this->events[] = 'reviewer';

            return test_agent_thread($group, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->events[] = 'implementer:'.$task->position;

            return test_agent_thread($task->taskGroup, 'implementer-'.$task->position, $task)->id;
        }

        public function requestReview(Task $task): void
        {
            $this->events[] = 'review:'.$task->position;
        }
    };
}

function scheduler_bind_claim(AppInstance $instance, AgentSpawner $spawner): void
{
    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private AppInstance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
        {
            return $this->instance;
        }
    });
    app()->instance(AgentSpawner::class, $spawner);
    app()->instance(TaskSettleMetricsCollector::class, new LocalTaskSettleMetricsCollector(
        new TaskGroupMetricsRefresher(test_agent_observer(new NullT3ThreadReader), new NullTaskWorkspaceDiffReader),
    ));
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);
}

it('reserves queued groups without a per-Project ceiling', function (): void {
    $app = scheduler_app('ceiling-app');
    $first = queued_group($app, 'One');
    $second = queued_group($app, 'Two');
    $third = queued_group($app, 'Three');
    $fourth = queued_group($app, 'Four');

    $scheduler = app(TaskScheduler::class);

    expect($scheduler->claimNext())->toBeNull()
        ->and($first->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and($second->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and($third->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and($fourth->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and(app(TaskConcurrencyGuard::class)->activeForApp($app->id))->toBe(0);
});

it('does not count completed groups toward the App ceiling', function (): void {
    $app = scheduler_app('completed-app');
    TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Done',
        'brief' => 'Already settled',
        'status' => TaskGroupStatus::Completed,
    ]);
    $queued = queued_group($app, 'Next');

    expect(app(TaskScheduler::class)->claimNext())->toBeNull()
        ->and($queued->fresh()?->status)->toBe(TaskGroupStatus::Todo);
});

it('claims another group when three reserved groups already occupy the App', function (): void {
    $app = scheduler_app('full-app');
    foreach (['A', 'B', 'C'] as $title) {
        TaskGroup::query()->create([
            'app_id' => $app->id,
            'title' => $title,
            'brief' => $title,
            'status' => TaskGroupStatus::Reserved,
        ]);
    }
    $queued = queued_group($app, 'Overflow');

    expect(app(TaskScheduler::class)->claimNext())->toBeNull()
        ->and($queued->fresh()?->status)->toBe(TaskGroupStatus::Todo);
});

it('applies the Node ceiling only after an App instance is assigned', function (): void {
    $app = scheduler_app('node-app');
    $node = scheduler_node('task-node', '10.44.0.90');
    $instance = scheduler_instance($app, $node, 'shared');

    foreach (range(1, TaskCeilings::PerNode) as $index) {
        $owner = scheduler_app("node-owner-{$index}");
        $placed = scheduler_instance($owner, $node, "slot-{$index}");
        $group = TaskGroup::query()->create([
            'app_id' => $owner->id,
            'title' => "Active {$index}",
            'brief' => 'Occupies the node',
            'status' => TaskGroupStatus::Running,
        ]);
        $group->taskable()->associate($placed);
        $group->save();
    }

    $queued = queued_group($app, 'Blocked', $instance);

    expect(app(TaskConcurrencyGuard::class)->activeForNode($node->id))->toBe(TaskCeilings::PerNode)
        ->and(app(TaskScheduler::class)->claimNext())->toBeNull()
        ->and($queued->fresh()?->status)->toBe(TaskGroupStatus::Todo);
});

it('starts a group when provisioning assigns an instance under both ceilings', function (): void {
    $app = scheduler_app('orbit');
    $node = scheduler_node('orbit-node', '10.44.0.91');
    $instance = scheduler_instance($app, $node, 'isolated');
    $group = queued_group($app, 'Wire T3');

    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private AppInstance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
        {
            expect($intent->visitable)->toBeFalse();

            return $this->instance;
        }
    });
    app()->instance(AgentSpawner::class, new class implements AgentSpawner
    {
        public function spawnReviewer(Task $task): ?int
        {
            $group = $task->taskGroup;

            return test_agent_thread($group, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->taskGroup, 'implementer-thread', $task)->id;
        }

        public function requestReview(Task $task): void {}
    });

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $claimed = $claimed?->fresh(['tasks', 'app', 'taskable']);

    expect($claimed)->not->toBeNull()
        ->and($claimed?->status)->toBe(TaskGroupStatus::Running)
        ->and($claimed?->taskable_id)->toBe($instance->id)
        ->and($claimed?->reviewer_agent_thread_id)->toBeNull()
        ->and($claimed?->tasks->first()?->status)->toBe(TaskStatus::Running)
        ->and($claimed?->tasks->first()?->implementer_agent_thread_id)->toBe(AgentThread::query()->where('external_id', 'implementer-thread')->sole()->id)
        ->and(app(TaskRunReceipts::class)->prepared)->toBe(['implementer']);
});

it('fails a group and its first task when the run script cannot be installed', function (): void {
    $app = scheduler_app('orbit');
    $node = scheduler_node('orbit-node', '10.44.0.91');
    $instance = scheduler_instance($app, $node, 'isolated');
    $group = queued_group($app, 'Wire T3');
    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private AppInstance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
        {
            return $this->instance;
        }
    });
    $spawner = new class implements AgentSpawner
    {
        public int $implementers = 0;

        public function spawnReviewer(Task $task): ?int
        {
            $group = $task->taskGroup;

            return test_agent_thread($group, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->implementers++;

            return null;
        }

        public function requestReview(Task $task): void {}
    };
    app()->instance(AgentSpawner::class, $spawner);
    mock(TaskRunReceipts::class)->shouldReceive('prepare')->andThrow(new TaskRunReceiptException('The task workspace could not be reached for the run receipt.'));

    app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Failed)
        ->and($group->tasks()->first()?->status)->toBe(TaskStatus::Failed)
        ->and($spawner->implementers)->toBe(0);
});

it('keeps the task in review and counts a communication failure when the reviewer spawn at the first handoff returns no thread id', function (): void {
    $app = scheduler_app('missing-reviewer');
    $instance = scheduler_instance($app, scheduler_node('missing-reviewer-node', '10.44.0.96'), 'workspace');
    $group = queued_group($app, 'Missing reviewer');

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
            return test_agent_thread($task->taskGroup, 'implementer-thread', $task)->id;
        }

        public function requestReview(Task $task): void {}
    });

    app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $task = $group->tasks()->sole();
    app(TaskScheduler::class)->settleImplementer($task);

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing)
        ->and($group->fresh()?->reviewer_agent_thread_id)->toBeNull()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->communication_failures)->toBe(1)
        ->and($task->fresh()?->review_notified_attempt)->toBeNull()
        ->and(app(TaskRunReceipts::class)->prepared)->toBe(['implementer', 'reviewer:final']);
});

it('fails a group and its first task when the implementer spawn returns no thread id', function (): void {
    $app = scheduler_app('missing-implementer');
    $instance = scheduler_instance($app, scheduler_node('missing-implementer-node', '10.44.0.97'), 'workspace');
    $group = queued_group($app, 'Missing implementer');

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
            $group = $task->taskGroup;

            return test_agent_thread($group, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return null;
        }

        public function requestReview(Task $task): void {}
    });

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $claimed = $claimed?->fresh(['tasks', 'app', 'taskable']);

    expect($claimed?->status)->toBe(TaskGroupStatus::Failed)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Failed)
        ->and($group->fresh()?->reviewer_agent_thread_id)->toBeNull()
        ->and($group->tasks->first()?->fresh()?->status)->toBe(TaskStatus::Failed)
        ->and($group->tasks->first()?->fresh()?->implementer_agent_thread_id)->toBeNull();
});

it('fails the group when a later implementer spawn returns no thread id', function (): void {
    $app = scheduler_app('missing-next-implementer');
    $instance = scheduler_instance($app, scheduler_node('missing-next-node', '10.44.0.98'), 'workspace');
    $group = queued_group($app, 'Missing next implementer', $instance);
    Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 2,
        'title' => 'Second',
        'brief' => 'Next subtask',
        'status' => TaskStatus::Todo,
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
            $group = $task->taskGroup;

            return test_agent_thread($group, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return $task->position === 1 ? test_agent_thread($task->taskGroup, 'implementer-1', $task)->id : null;
        }

        public function requestReview(Task $task): void {}
    });

    $claimed = app(TaskScheduler::class)->claimNext();
    $reviewing = app(TaskScheduler::class)->settleImplementer($claimed?->tasks->first() ?? $group->tasks->first());
    $advanced = app(TaskScheduler::class)->acceptReview($reviewing->tasks->first());

    expect($advanced->status)->toBe(TaskGroupStatus::Failed)
        ->and($advanced->tasks->first()?->status)->toBe(TaskStatus::Completed)
        ->and($advanced->tasks->last()?->status)->toBe(TaskStatus::Failed)
        ->and($advanced->tasks->last()?->implementer_agent_thread_id)->toBeNull();
});

it('returns a provisioned group to todo on its Instance when the Node is already at the ceiling', function (): void {
    $app = scheduler_app('held-app');
    $node = scheduler_node('full-node', '10.44.0.92');
    $instance = scheduler_instance($app, $node, 'held');

    foreach (range(1, TaskCeilings::PerNode) as $index) {
        $owner = scheduler_app("fill-owner-{$index}");
        $placed = scheduler_instance($owner, $node, "fill-{$index}");
        $group = TaskGroup::query()->create([
            'app_id' => $owner->id,
            'title' => "Fill {$index}",
            'brief' => 'Fills the node',
            'status' => TaskGroupStatus::Reviewing,
        ]);
        $group->taskable()->associate($placed);
        $group->save();
    }

    $queued = queued_group($app, 'Wait');
    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private AppInstance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
        {
            return $this->instance;
        }
    });

    $claimed = app(TaskScheduler::class)->claimNext();

    expect($claimed)->toBeNull()
        ->and($queued->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and($queued->fresh()?->taskable_id)->toBe($instance->id)
        ->and($queued->fresh()?->reviewer_agent_thread_id)->toBeNull();
});

it('keeps a planning group on its workspace while its Node is at the ceiling', function (): void {
    $app = scheduler_app('planned-app');
    $node = scheduler_node('planned-full-node', '10.44.0.93');
    $instance = scheduler_instance($app, $node, 'planned');

    foreach (range(1, TaskCeilings::PerNode) as $index) {
        $owner = scheduler_app("planned-fill-{$index}");
        $placed = scheduler_instance($owner, $node, "planned-fill-{$index}");
        $group = TaskGroup::query()->create([
            'app_id' => $owner->id,
            'title' => "Fill {$index}",
            'brief' => 'Fills the node',
            'status' => TaskGroupStatus::Reviewing,
        ]);
        $group->taskable()->associate($placed);
        $group->save();
    }

    $planned = queued_group($app, 'Planned');
    $planned->update(['plan' => true]);
    $planned->taskable()->associate($instance);
    $planned->save();

    expect(app(TaskScheduler::class)->claimNext())->toBeNull()
        ->and($planned->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and($planned->fresh()?->taskable_id)->toBe($instance->id);
});

it('advances a claimed Orbit group to running when the real provisioner and T3 spawner succeed', function (): void {
    $app = scheduler_app('orbit');
    $app->update(['root' => 'public']);
    $node = scheduler_node('real-wire', '10.44.0.94');
    $node->update(['user' => 'orbit', 'tld' => 'test', 'settings' => ['apps' => ['path' => '/srv/orbit/apps']]]);
    $node->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $node->processes()->create([
        'name' => 't3-code',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/home/orbit',
        'runtime_config' => ['command' => ['/home/orbit/.local/bin/t3', 'serve', '--port=3773']],
        'restart_policy' => 'always',
        'keep_alive' => true,
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
    $group = queued_group($app, 'Real wire');

    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    app()->instance(AppInstanceDestinationGuard::class, new class implements AppInstanceDestinationGuard
    {
        public function assertUnoccupied(Node $node, StoragePath $destination): void {}
    });
    app()->instance(DevelopmentAppInstanceSourceLifecycle::class, new class implements DevelopmentAppInstanceSourceLifecycle
    {
        public function prepare(AppInstance $appInstance, bool $allowExisting): void {}

        public function inspectPrepared(AppInstance $appInstance): void {}

        public function resolve(AppInstance $appInstance): DevelopmentSourceResolution
        {
            return new DevelopmentSourceResolution($appInstance->name, str_repeat('c', 40));
        }

        public function inspectResolved(AppInstance $appInstance): DevelopmentSourceResolution
        {
            return new DevelopmentSourceResolution((string) $appInstance->branch, (string) $appInstance->starting_commit);
        }
    });
    app()->instance(T3Dispatcher::class, new class implements T3Dispatcher
    {
        public function dispatch(Node $node, array $command): array
        {
            $threadId = is_string($command['threadId'] ?? null) ? $command['threadId'] : 't3-thread';

            return ['sequence' => 1, 'thread_id' => $threadId];
        }
    });
    app()->instance(TaskWorkspaceSigner::class, new class implements TaskWorkspaceSigner
    {
        public function commit(AppInstance $instance, string $message): ?string
        {
            return str_repeat('d', 40);
        }
    });

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $claimed = $claimed?->fresh(['tasks', 'app', 'taskable']);

    expect($claimed?->id)->toBe($group->id)
        ->and($claimed?->status)->toBe(TaskGroupStatus::Running)
        ->and($claimed?->taskable_id)->not->toBeNull()
        ->and($claimed?->taskable)->toBeInstanceOf(AppInstance::class)
        ->and($claimed?->taskable?->status)->toBe(AppInstanceState::SourceResolved)
        ->and($claimed?->taskable?->routes()->count())->toBe(0)
        ->and($claimed?->reviewer_agent_thread_id)->toBeNull()
        ->and($claimed?->tasks->first()?->status)->toBe(TaskStatus::Running)
        ->and($claimed?->tasks->first()?->implementer_agent_thread_id)->not->toBeNull();
});

it('starts only the first pending subtask when a claimed group has later siblings', function (): void {
    $app = scheduler_app('opening-order-app');
    $node = scheduler_node('opening-order-node', '10.44.0.96');
    $instance = scheduler_instance($app, $node, 'opening-order');
    $group = queued_group($app, 'Opening order', $instance);
    scheduler_pending_task($group, 2, 'Second');
    $spawner = scheduler_recording_spawner();
    scheduler_bind_claim($instance, $spawner);

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $claimed = $claimed?->fresh(['tasks', 'app', 'taskable']);
    $tasks = $claimed?->tasks->sortBy(fn (Task $task): array => [$task->position, $task->id])->values();

    expect($claimed?->status)->toBe(TaskGroupStatus::Running)
        ->and($tasks?->pluck('status')->all())->toBe([TaskStatus::Running, TaskStatus::Todo])
        ->and($tasks?->get(0)?->implementer_agent_thread_id)->toBe(AgentThread::query()->where('external_id', 'implementer-1')->sole()->id)
        ->and($tasks?->get(1)?->implementer_agent_thread_id)->toBeNull()
        ->and($spawner->events)->toBe(['implementer:1']);
});

it('rejects starting a later subtask while a sibling is still running', function (): void {
    $app = scheduler_app('second-running-app');
    $node = scheduler_node('second-running-node', '10.44.0.97');
    $instance = scheduler_instance($app, $node, 'second-running');
    $group = queued_group($app, 'Second running', $instance);
    scheduler_pending_task($group, 2, 'Second');
    $spawner = scheduler_recording_spawner();
    scheduler_bind_claim($instance, $spawner);

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $claimed = $claimed?->fresh(['tasks', 'app', 'taskable']);
    $second = $claimed?->tasks
        ->sortBy(fn (Task $task): array => [$task->position, $task->id])
        ->values()
        ->get(1);

    expect(fn () => app(TaskScheduler::class)->startTask($second ?? $group->tasks->last()))
        ->toThrow(TaskSequenceException::class);

    $tasks = ($claimed?->fresh(['tasks']) ?? $group)->tasks
        ->sortBy(fn (Task $task): array => [$task->position, $task->id])
        ->values();

    expect($tasks->pluck('status')->all())->toBe([TaskStatus::Running, TaskStatus::Todo])
        ->and($tasks->get(1)?->implementer_agent_thread_id)->toBeNull()
        ->and($spawner->events)->toBe(['implementer:1']);
});

it('starts the next pending subtask as the sole running task after review is accepted', function (): void {
    $app = scheduler_app('accept-next-app');
    $node = scheduler_node('accept-next-node', '10.44.0.98');
    $instance = scheduler_instance($app, $node, 'accept-next');
    $group = queued_group($app, 'Accept next', $instance);
    scheduler_pending_task($group, 2, 'Second');
    $spawner = scheduler_recording_spawner();
    scheduler_bind_claim($instance, $spawner);

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $claimed = $claimed?->fresh(['tasks', 'app', 'taskable']);
    $reviewing = app(TaskScheduler::class)->settleImplementer($claimed?->tasks->first() ?? $group->tasks->first());
    $advanced = app(TaskScheduler::class)->acceptReview($reviewing->tasks->first());
    $tasks = $advanced->tasks->sortBy(fn (Task $task): array => [$task->position, $task->id])->values();

    expect($advanced->status)->toBe(TaskGroupStatus::Running)
        ->and($tasks->pluck('status')->all())->toBe([TaskStatus::Completed, TaskStatus::Running])
        ->and($tasks->filter(fn (Task $task): bool => $task->status === TaskStatus::Running)->count())->toBe(1)
        ->and($tasks->get(1)?->implementer_agent_thread_id)->toBe(AgentThread::query()->where('external_id', 'implementer-2')->sole()->id)
        ->and($spawner->events)->toBe(['implementer:1', 'reviewer', 'implementer:2']);
});

it('starts the reviewer at the first handoff, reuses it for later handoffs, and starts the next implementer after approval', function (): void {
    $app = scheduler_app('handoff-app');
    $node = scheduler_node('handoff-node', '10.44.0.93');
    $instance = scheduler_instance($app, $node, 'handoff');
    $group = queued_group($app, 'Handoff', $instance);
    Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 2,
        'title' => 'Second',
        'brief' => 'Next subtask',
        'status' => TaskStatus::Todo,
    ]);
    $spawner = new class implements AgentSpawner
    {
        /** @var list<string> */
        public array $events = [];

        public function spawnReviewer(Task $task): ?int
        {
            $group = $task->taskGroup;

            $this->events[] = 'reviewer';

            return test_agent_thread($group, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->events[] = 'implementer:'.$task->position;

            return test_agent_thread($task->taskGroup, 'implementer-'.$task->position, $task)->id;
        }

        public function requestReview(Task $task): void
        {
            $this->events[] = 'review:'.$task->position;
        }
    };

    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private AppInstance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
        {
            return $this->instance;
        }
    });
    app()->instance(AgentSpawner::class, $spawner);
    app()->instance(TaskSettleMetricsCollector::class, new LocalTaskSettleMetricsCollector(
        new TaskGroupMetricsRefresher(test_agent_observer(new NullT3ThreadReader), new NullTaskWorkspaceDiffReader),
    ));
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $claimed = $claimed?->fresh(['tasks', 'app', 'taskable']);
    $first = $claimed?->tasks->first();

    expect($claimed?->status)->toBe(TaskGroupStatus::Running)
        ->and($first?->status)->toBe(TaskStatus::Running)
        ->and($first?->implementer_agent_thread_id)->toBe(AgentThread::query()->where('external_id', 'implementer-1')->sole()->id);

    $reviewing = app(TaskScheduler::class)->settleImplementer($first ?? $group->tasks->first());

    expect($reviewing->status)->toBe(TaskGroupStatus::Reviewing)
        ->and($reviewing->tasks->first()?->status)->toBe(TaskStatus::Reviewing)
        ->and($spawner->events)->toBe(['implementer:1', 'reviewer']);

    $advanced = app(TaskScheduler::class)->acceptReview($reviewing->tasks->first());

    expect($advanced->status)->toBe(TaskGroupStatus::Running)
        ->and($advanced->tasks->first()?->status)->toBe(TaskStatus::Completed)
        ->and($advanced->tasks->last()?->status)->toBe(TaskStatus::Running)
        ->and($advanced->tasks->last()?->implementer_agent_thread_id)->toBe(AgentThread::query()->where('external_id', 'implementer-2')->sole()->id)
        ->and($spawner->events)->toBe(['implementer:1', 'reviewer', 'implementer:2']);

    $lastReview = app(TaskScheduler::class)->settleImplementer($advanced->tasks->last());

    expect($spawner->events)->toBe(['implementer:1', 'reviewer', 'implementer:2', 'review:2']);
    $settled = app(TaskScheduler::class)->acceptReview($lastReview->tasks->last());

    expect($settled->status)->toBe(TaskGroupStatus::Settling)
        ->and($settled->tasks->pluck('status')->all())->toBe([
            TaskStatus::Completed,
            TaskStatus::Completed,
        ]);
});

it('keeps the reviewed pull request, writes settle metrics, and notifies Coder after the last sign-off', function (): void {
    $this->freezeTime();
    $app = scheduler_app('settle-app');
    $node = scheduler_node('settle-node', '10.44.0.95');
    $instance = scheduler_instance($app, $node, 'settle');
    $group = queued_group($app, 'Settle', $instance);
    $group->notify_coder = true;
    $group->pr_url = 'https://github.com/nckrtl/orbit/pull/543';
    $group->save();
    $first = $group->tasks->first();
    $first?->update(['tokens' => 40]);
    $metrics = new class implements TaskSettleMetricsCollector
    {
        public function collect(TaskGroup $group): TaskSettleMetrics
        {
            return new TaskSettleMetrics(tokens: 40, lineDiff: 12, durationMs: 1500);
        }
    };
    $notifier = new class implements CoderSettleNotifier
    {
        public ?TaskGroup $notified = null;

        public function notify(TaskGroup $group): void
        {
            $this->notified = $group;
        }

        public function escalate(TaskGroup $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

        public function assistance(TaskGroup $group, string $reason): void {}
    };

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
            $group = $task->taskGroup;

            return test_agent_thread($group, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->taskGroup, 'implementer-'.$task->position, $task)->id;
        }

        public function requestReview(Task $task): void {}
    });
    app()->instance(TaskSettleMetricsCollector::class, $metrics);
    app()->instance(CoderSettleNotifier::class, $notifier);

    $claimed = app(TaskScheduler::class)->claimNext();
    $reviewing = app(TaskScheduler::class)->settleImplementer($claimed?->tasks->first() ?? $group->tasks->first());
    $settled = app(TaskScheduler::class)->acceptReview($reviewing->tasks->first());

    expect($settled->status)->toBe(TaskGroupStatus::Settling)
        ->and($settled->pr_url)->toBe('https://github.com/nckrtl/orbit/pull/543')
        ->and($settled->tokens)->toBe(40)
        ->and($settled->line_diff)->toBe(12)
        ->and($settled->duration_ms)->toBe(1500)
        ->and($settled->settled_at)->not->toBeNull()
        ->and($notifier->notified?->id)->toBe($settled->id)
        ->and($notifier->notified?->pr_url)->toBe('https://github.com/nckrtl/orbit/pull/543');
});

it('runs the Project setup steps and check on the fresh workspace before the first implementer starts', function (): void {
    $app = scheduler_app('baseline-app');
    $app->update(['task_check' => 'composer check']);
    $instance = scheduler_instance($app, scheduler_node('baseline-node', '10.44.0.94'), 'baseline');
    $group = queued_group($app, 'Baseline', $instance);
    ProjectLifecycleStep::query()->create(['app_id' => $app->id, 'phase' => 'setup', 'name' => 'Install', 'command' => 'composer install', 'timeout_seconds' => 600, 'position' => 1]);
    $spawner = scheduler_recording_spawner();
    scheduler_bind_claim($instance, $spawner);
    $checks = new FakeTaskCheckRunner([TaskCheckReading::running(), FakeTaskCheckRunner::passed()]);
    app()->instance(TaskCheckRunner::class, $checks);

    app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    $check = TaskCheck::query()->sole();
    expect($spawner->events)->toBe([])
        ->and($checks->commands)->toBe(['composer check'])
        ->and($check->kind)->toBe(TaskCheckKind::Baseline)
        ->and($check->task_comment_id)->toBeNull()
        ->and($checks->setups)->toBe([[['name' => 'Install', 'command' => 'composer install', 'timeout_seconds' => 600], ['name' => '[Orbit internal] Install Composer dependencies', 'command' => 'while IFS= read -r -d "" manifest; do project="${manifest%/composer.json}"; [ "$project" = "$manifest" ] && project="."; if { [ "$project" = "." ] || [ -f "$project/composer.lock" ]; } && [ ! -f "$project/vendor/autoload.php" ]; then (cd "$project" && if [ -f composer.lock ]; then composer install --no-interaction --prefer-dist; else composer install --no-interaction --prefer-dist && rm -f composer.lock; fi) || exit $?; fi; done < <(git ls-files -z -- "composer.json" ":(glob)**/composer.json")', 'timeout_seconds' => 600]]]);

    test_pass_baseline();

    expect($check->fresh()?->status)->toBe(TaskCheckStatus::Passed)
        ->and($spawner->events)->toBe(['implementer:1']);
});

it('prepares Composer and JavaScript dependencies referenced by a custom baseline command', function (): void {
    $app = scheduler_app('custom-baseline-command');
    $app->update(['task_check' => 'composer test && bun run check']);
    $instance = scheduler_instance($app, scheduler_node('custom-baseline-node', '10.44.0.98'), 'custom-check');
    queued_group($app, 'Custom baseline command', $instance);
    scheduler_bind_claim($instance, scheduler_recording_spawner());
    $checks = new FakeTaskCheckRunner([TaskCheckReading::running()]);
    app()->instance(TaskCheckRunner::class, $checks);

    app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    expect(array_column($checks->setups[0], 'name'))->toBe([
        '[Orbit internal] Install Composer dependencies',
        '[Orbit internal] Install JavaScript dependencies',
    ])->and($checks->commands)->toBe(['composer test && bun run check']);
});

it('prepares Composer dependencies only when the baseline command runs composer or uses vendor', function (string $command, bool $installs): void {
    $app = scheduler_app('composer-trigger');
    $app->update(['task_check' => $command]);
    $instance = scheduler_instance($app, scheduler_node('composer-trigger-node', '10.44.0.99'), 'composer-trigger');
    queued_group($app, 'Composer trigger', $instance);
    scheduler_bind_claim($instance, scheduler_recording_spawner());
    $checks = new FakeTaskCheckRunner([TaskCheckReading::running()]);
    app()->instance(TaskCheckRunner::class, $checks);

    app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    expect(in_array('[Orbit internal] Install Composer dependencies', array_column($checks->setups[0], 'name'), true))->toBe($installs);
})->with([
    'composer command' => ['composer test', true],
    'composer after a shell operator' => ['cd app&&composer', true],
    'vendor binary' => ['vendor/bin/pest', true],
    'composer.json file name' => ['test -f composer.json && echo ok', false],
    'composer in another word' => ['./mycomposer check', false],
]);

it('passes an unset Project baseline without a command and starts the first implementer', function (): void {
    $app = scheduler_app('no-baseline-command');
    $instance = scheduler_instance($app, scheduler_node('no-baseline-command-node', '10.44.0.97'), 'no-check');
    $group = queued_group($app, 'No baseline command', $instance);
    $spawner = scheduler_recording_spawner();
    scheduler_bind_claim($instance, $spawner);
    $checks = new FakeTaskCheckRunner([TaskCheckReading::running(), FakeTaskCheckRunner::passed()]);
    app()->instance(TaskCheckRunner::class, $checks);

    app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    test_pass_baseline();

    expect($checks->commands)->toBe([null])
        ->and($spawner->events)->toBe(['implementer:1'])
        ->and($group->fresh()?->assistance_requested)->toBeFalse();
});

it('asks for assistance, and starts no agent, when the fresh workspace fails its check', function (?string $failedStep, string $reason): void {
    $app = scheduler_app('broken-main');
    $app->update(['task_check' => 'composer check']);
    $instance = scheduler_instance($app, scheduler_node('broken-node', '10.44.0.95'), 'broken');
    $group = queued_group($app, 'Broken main', $instance);
    $spawner = scheduler_recording_spawner();
    scheduler_bind_claim($instance, $spawner);
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "FAILED\n", null, str_repeat('b', 40), $failedStep),
    ]));

    app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    expect($spawner->events)->toBe([])
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_reason)->toStartWith($reason)
        ->and(TaskCheck::query()->sole()->failed_step)->toBe($failedStep);
})->with([
    'composer check' => [null, 'The Project baseline check failed with exit code 1 on a fresh checkout of task-'],
    'setup step' => ['Install', 'The Project setup step "Install" failed with exit code 1 on a fresh checkout of task-'],
]);

/**
 * A reviewing subtask whose reviewer has approved it. Unless it is the last, a later subtask waits behind it.
 *
 * @return array{TaskGroup, Task, object, object}
 */
function scheduler_approved_subtask(string $slug, bool $last = false, ?string $receipt = null): array
{
    $app = scheduler_app($slug);
    $instance = scheduler_instance($app, scheduler_node($slug.'-node', '10.44.0.'.$app->id), $slug);
    $group = queued_group($app, 'Push', $instance);
    $task = $group->tasks->first();
    if (! $task instanceof Task) {
        throw new RuntimeException('The group has no subtask.');
    }
    if (! $last) {
        scheduler_pending_task($group, 2, 'Routes');
    }
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update([
        'status' => TaskStatus::Reviewing,
        'review_attempt' => 1,
        'review_notified_attempt' => 1,
        'review_notified_turn_id' => 'handoff-turn',
    ]);
    test_link_agent_threads($group);
    app(TaskExtensionState::class)->enable();
    app()->instance(T3Dispatcher::class, new class implements T3Dispatcher
    {
        public function dispatch(Node $node, array $command): array
        {
            return ['sequence' => 1, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    });
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => 'review-turn', 'state' => 'completed']]];
        }
    });
    app()->instance(TaskWorkspaceStateReader::class, new readonly class('task-'.$group->id) implements TaskWorkspaceStateReader
    {
        public function __construct(private string $branch) {}

        public function headCommit(AppInstance $instance): ?string
        {
            return null;
        }

        public function currentBranch(AppInstance $instance): ?string
        {
            return $this->branch;
        }

        public function definesComposerCheckScript(AppInstance $instance): bool
        {
            return true;
        }
    });
    app()->instance(TaskRunReceipts::class, new FakeTaskRunReceipts([
        $receipt ?? FakeTaskRunReceipts::contents('approved', 'Checked the models.'),
    ]));
    $signer = new class implements TaskWorkspaceSigner
    {
        /** @var list<string> */
        public array $messages = [];

        public function commit(AppInstance $instance, string $message): ?string
        {
            $this->messages[] = $message;
            $sha = str_repeat('c', 40);
            // Orbit's commit moves the workspace HEAD, which the retry compares with commit_sha (ADR 0133).
            $checks = app(TaskCheckRunner::class);
            if ($checks instanceof FakeTaskCheckRunner) {
                $checks->head = $sha;
            }

            return $sha;
        }
    };
    $publisher = new class implements TaskPullRequestPublisher
    {
        /** @var list<int> */
        public array $pushes = [];

        /** @var list<string> */
        public array $commits = [];

        /** @var list<string> */
        public array $bodies = [];

        public int $pushFailures = 0;

        public function publish(TaskGroup $group, string $body, string $commit): string
        {
            $this->bodies[] = $body;
            $this->commits[] = $commit;

            return 'https://github.com/acme/orbit/pull/42';
        }

        public function push(TaskGroup $group, string $commit): void
        {
            $this->pushes[] = $group->id;
            $this->commits[] = $commit;
            if ($this->pushFailures > 0) {
                $this->pushFailures--;

                throw new TaskPullRequestException('The task branch could not be pushed.');
            }
        }
    };
    app()->instance(TaskWorkspaceSigner::class, $signer);
    app()->instance(TaskPullRequestPublisher::class, $publisher);
    app()->instance(TaskBriefCoverage::class, new class implements TaskBriefCoverage
    {
        public function missing(TaskGroup $group, TaskRunPullRequest $pullRequest): array
        {
            return [];
        }
    });
    app()->instance(TaskSettleMetricsCollector::class, new class implements TaskSettleMetricsCollector
    {
        public function collect(TaskGroup $group): TaskSettleMetrics
        {
            return new TaskSettleMetrics(tokens: 1, lineDiff: 1, durationMs: 1);
        }
    });
    app()->instance(AgentSpawner::class, scheduler_recording_spawner());

    return [$group->fresh(['app', 'tasks', 'taskable']) ?? $group, $task, $signer, $publisher];
}

function scheduler_final_approval(): string
{
    return json_encode([
        'outcome' => 'approved',
        'summary' => 'Checked the feature.',
        'pull_request' => ['summary' => 'Adds the export.', 'changes' => ['Tasks store their records.'], 'breaking' => []],
        'nonce' => bin2hex(random_bytes(8)),
    ], JSON_THROW_ON_ERROR);
}

it('pushes each approved subtask to the task branch before the next one starts', function (): void {
    [$group, $task, $signer, $publisher] = scheduler_approved_subtask('push-each');

    app(TaskScheduler::class)->tick();

    expect($publisher->pushes)->toBe([$group->id])
        ->and($publisher->bodies)->toBe([])
        ->and($signer->messages)->toBe(["Push first\n\nChecked the models."])
        ->and($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40))
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and(Task::query()->where('title', 'Routes')->sole()->status)->toBe(TaskStatus::Running)
        ->and($group->fresh()?->pr_url)->toBeNull()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running);
});

it('retries a failed push of an approved subtask without committing again', function (): void {
    [$group, $task, $signer, $publisher] = scheduler_approved_subtask('push-retry');
    $publisher->pushFailures = 1;
    $sha = str_repeat('c', 40);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->communication_failures)->toBe(1)
        ->and($task->comments()->sole()->commit_sha)->toBe($sha)
        ->and($signer->messages)->toHaveCount(1)
        ->and($publisher->pushes)->toBe([$group->id])
        ->and($publisher->bodies)->toBe([])
        ->and(Task::query()->where('title', 'Routes')->sole()->status)->toBe(TaskStatus::Todo);

    app(TaskScheduler::class)->tick();

    expect($task->comments()->count())->toBe(1)
        ->and($task->comments()->sole()->commit_sha)->toBe($sha)
        ->and($signer->messages)->toHaveCount(1)
        ->and($publisher->pushes)->toBe([$group->id, $group->id])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and(Task::query()->where('title', 'Routes')->sole()->status)->toBe(TaskStatus::Running);
});

it('retries a failed push when the reviewer is unavailable', function (): void {
    [$group, $task, $signer, $publisher] = scheduler_approved_subtask('push-unavailable');
    $publisher->pushFailures = 1;
    $sha = str_repeat('c', 40);

    app(TaskScheduler::class)->tick();

    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return null;
        }
    });

    app(TaskScheduler::class)->tick();

    expect($publisher->pushes)->toBe([$group->id, $group->id])
        ->and($signer->messages)->toHaveCount(1)
        ->and($task->comments()->count())->toBe(1)
        ->and($task->comments()->sole()->commit_sha)->toBe($sha)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and(Task::query()->where('title', 'Routes')->sole()->status)->toBe(TaskStatus::Running)
        ->and($group->fresh()?->agent_unavailable_since)->toBeNull();
});

it('pushes the last approved subtask and opens one pull request', function (): void {
    [$group, $task, $signer, $publisher] = scheduler_approved_subtask('push-last', last: true, receipt: scheduler_final_approval());

    app(TaskScheduler::class)->tick();

    expect($publisher->pushes)->toBe([$group->id])
        ->and($publisher->bodies)->toHaveCount(1)
        ->and($signer->messages)->toHaveCount(1)
        ->and($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40))
        ->and($group->fresh()?->pr_url)->toBe('https://github.com/acme/orbit/pull/42')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed);
});

it('keeps retrying a failed push after the fifth failure asks for assistance', function (): void {
    [$group, $task, $signer, $publisher] = scheduler_approved_subtask('push-assist');
    $publisher->pushFailures = 6;
    $sha = str_repeat('c', 40);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        app(TaskScheduler::class)->tick();
    }

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->comments()->sole()->commit_sha)->toBe($sha)
        ->and($signer->messages)->toHaveCount(1)
        ->and($publisher->pushes)->toHaveCount(6)
        ->and(Task::query()->where('title', 'Routes')->sole()->status)->toBe(TaskStatus::Todo);

    app(TaskScheduler::class)->tick();

    expect($task->comments()->count())->toBe(1)
        ->and($task->comments()->sole()->commit_sha)->toBe($sha)
        ->and($signer->messages)->toHaveCount(1)
        ->and($publisher->pushes)->toHaveCount(7)
        ->and($publisher->bodies)->toBe([])
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and(Task::query()->where('title', 'Routes')->sole()->status)->toBe(TaskStatus::Running);
});

it('pushes the stored commit rather than HEAD', function (): void {
    [$group, $task, , $publisher] = scheduler_approved_subtask('push-stored-commit');
    $stored = str_repeat('c', 40);

    app(TaskScheduler::class)->tick();

    expect($publisher->commits)->toBe([$stored])
        ->and($publisher->pushes)->toBe([$group->id])
        ->and($task->comments()->sole()->commit_sha)->toBe($stored)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed);
});

it('does not run a baseline for a group whose implementers already started', function (): void {
    $app = scheduler_app('started-app');
    $instance = scheduler_instance($app, scheduler_node('started-node', '10.44.0.96'), 'started');
    $group = queued_group($app, 'Started', $instance);
    scheduler_pending_task($group, 2, 'Second');
    $spawner = scheduler_recording_spawner();
    scheduler_bind_claim($instance, $spawner);
    app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $first = $group->tasks()->orderBy('position')->first();
    $reviewing = app(TaskScheduler::class)->settleImplementer($first);

    app(TaskScheduler::class)->acceptReview($reviewing->tasks->first());

    expect($spawner->events)->toBe(['implementer:1', 'reviewer', 'implementer:2'])
        ->and(TaskCheck::query()->where('kind', TaskCheckKind::Baseline->value)->count())->toBe(1);
});

/**
 * A task in review. When `$notified` is false, the review request has not been sent, so the first tick records
 * the workspace. Otherwise the stored baseline matches the fake check runner until a test changes it.
 *
 * @param  list<string|null>  $receipts
 * @return array{TaskGroup, Task, FakeTaskRunReceipts, object, FakeTaskCheckRunner, object, object}
 */
function scheduler_review(array $receipts, bool $notified = true): array
{
    static $octet = 30;
    $octet++;
    $app = scheduler_app('review-ws-'.$octet);
    $node = scheduler_node('review-ws-node-'.$octet, '10.44.2.'.$octet);
    $instance = scheduler_instance($app, $node, 'workspace');
    $group = queued_group($app, 'Review workspace', $instance);
    scheduler_pending_task($group, 2, 'Second');
    $task = $group->tasks()->orderBy('position')->firstOrFail();
    $group->update([
        'status' => TaskGroupStatus::Reviewing,
        'reviewer_agent_thread_id' => test_agent_thread($group, 'reviewer-'.$octet)->id,
    ]);
    $task->update([
        'status' => TaskStatus::Reviewing,
        'implementer_agent_thread_id' => test_agent_thread($group, 'implementer-'.$octet, $task)->id,
        'review_notified_attempt' => $notified ? $task->review_attempt : null,
        'review_notified_turn_id' => $notified ? 'handoff-turn' : null,
        'review_workspace_head' => $notified ? str_repeat('a', 40) : null,
        'review_workspace_tree' => $notified ? str_repeat('b', 40) : null,
    ]);
    app(TaskExtensionState::class)->enable();
    app()->instance(TaskWorkspaceStateReader::class, new class($group->id) implements TaskWorkspaceStateReader
    {
        public function __construct(private int $groupId) {}

        public function headCommit(AppInstance $instance): ?string
        {
            return str_repeat('a', 40);
        }

        public function currentBranch(AppInstance $instance): ?string
        {
            return 'task-'.$this->groupId;
        }

        public function definesComposerCheckScript(AppInstance $instance): bool
        {
            return true;
        }
    });
    app()->instance(AgentSpawner::class, new class implements AgentSpawner
    {
        public function spawnReviewer(Task $task): ?int
        {
            return test_agent_thread($task->taskGroup, 'spawned-reviewer')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->taskGroup, 'spawned-implementer-'.$task->id, $task)->id;
        }

        public function requestReview(Task $task): void {}
    });
    $receipts = new FakeTaskRunReceipts($receipts);
    app()->instance(TaskRunReceipts::class, $receipts);
    $checks = app(TaskCheckRunner::class);
    if (! $checks instanceof FakeTaskCheckRunner) {
        throw new RuntimeException('The review test needs the fake check runner.');
    }
    $dispatcher = new class implements T3Dispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;

            return ['sequence' => count($this->commands), 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    app()->instance(T3Dispatcher::class, $dispatcher);
    $reader = new class($notified ? 'review-turn' : 'handoff-turn') implements T3ThreadReader
    {
        public function __construct(public string $turnId) {}

        public string $implementerTurnId = '';

        public string $implementerState = 'completed';

        public function snapshot(Node $node, string $threadId): ?array
        {
            $implementer = str_contains($threadId, 'implementer') && $this->implementerTurnId !== '';
            $state = $implementer ? $this->implementerState : 'completed';

            return ['thread' => [
                'session' => ['status' => $state === 'running' ? 'running' : 'done'],
                'latestTurn' => [
                    'id' => $implementer ? $this->implementerTurnId : $this->turnId,
                    'state' => $state,
                ],
            ]];
        }
    };
    app()->instance(T3ThreadReader::class, $reader);
    $signer = new class implements TaskWorkspaceSigner
    {
        /** @var list<string> */
        public array $messages = [];

        public function commit(AppInstance $instance, string $message): ?string
        {
            $this->messages[] = $message;

            return str_repeat('c', 40);
        }
    };
    app()->instance(TaskWorkspaceSigner::class, $signer);
    // ADR 0160: every approval pushes the task branch.
    app()->instance(TaskPullRequestPublisher::class, new class implements TaskPullRequestPublisher
    {
        public function publish(TaskGroup $group, string $body, string $commit): string
        {
            return 'https://github.com/acme/orbit/pull/1';
        }

        public function push(TaskGroup $group, string $commit): void {}
    });

    return [$group->fresh(['tasks', 'taskable']) ?? $group, $task->fresh() ?? $task, $receipts, $signer, $checks, $dispatcher, $reader];
}

it('approves a review when the workspace is unchanged since the review request', function (): void {
    [$group, $task, , $signer, $checks, $dispatcher, $reader] = scheduler_review([
        FakeTaskRunReceipts::contents('approved', 'Checked the models.'),
    ], notified: false);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->review_workspace_head)->toBe($checks->head)
        ->and($task->fresh()?->review_workspace_tree)->toBe($checks->tree)
        ->and($task->fresh()?->review_notified_attempt)->toBe($task->review_attempt)
        ->and($signer->messages)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);

    $reader->turnId = 'review-turn';
    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toBe([$task->title."\n\nChecked the models."])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40))
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands)->toBe([]);
    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Running);
});

it('refuses a review when the reviewer changed the workspace and asks for assistance on the second change', function (): void {
    [$group, $task, , $signer, $checks, $dispatcher, $reader] = scheduler_review([
        FakeTaskRunReceipts::contents('approved', 'Checked the models.'),
    ]);
    $checks->tree = str_repeat('c', 40);
    $reminder = 'Orbit could not confirm the review is complete. '.TaskScheduler::WorkspaceChangedReminder.' '.TaskRunInstructions::reviewer();

    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->review_handled_comment_id)->toBeNull()
        ->and($task->fresh()?->review_notified_turn_id)->toBe('review-turn')
        ->and($task->comments()->sole()->getRawOriginal('type'))->toBe('approved')
        ->and($task->comments()->sole()->commit_sha)->toBeNull()
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toBe($reminder);

    app(TaskScheduler::class)->tick();
    $checks->tree = str_repeat('b', 40);
    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->review_handled_comment_id)->toBeNull()
        ->and($dispatcher->commands)->toHaveCount(1);

    $checks->tree = str_repeat('c', 40);
    $reader->turnId = 'review-turn-2';
    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('Checks still failed after the reminder. '.TaskScheduler::WorkspaceChangedReminder)
        ->and($task->fresh()?->review_handled_comment_id)->toBe($task->comments()->sole()->id)
        ->and($dispatcher->commands)->toHaveCount(1);
});

it('applies the kept approval once the reviewer restores the workspace', function (): void {
    [, $task, , $signer, $checks, , $reader] = scheduler_review([
        FakeTaskRunReceipts::contents('approved', 'Checked the models.'),
    ]);
    $checks->tree = str_repeat('c', 40);

    app(TaskScheduler::class)->tick();
    $checks->tree = str_repeat('b', 40);
    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->assistance_requested)->toBeFalse();

    $reader->turnId = 'review-turn-2';
    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toBe([$task->title."\n\nChecked the models."])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and($task->fresh()?->assistance_requested)->toBeFalse();
});

it('counts an unreadable review workspace as a communication failure and does not treat it as a reviewer change', function (): void {
    [, $task, , $signer, $checks, $dispatcher] = scheduler_review([
        FakeTaskRunReceipts::contents('approved', 'Checked the models.'),
    ]);
    $checks->failSnapshot = true;

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->communication_failures)->toBe(1)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($signer->messages)->toBe([])
        ->and($dispatcher->commands)->toBe([]);

    $checks->failSnapshot = false;
    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toHaveCount(1)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and($task->fresh()?->communication_failures)->toBe(0);
});

it('does not relay findings or accept a blocked question when the reviewer changed the workspace', function (string $outcome, ?string $question, string $field): void {
    [$group, $task, , $signer, $checks, $dispatcher] = scheduler_review([
        FakeTaskRunReceipts::contents($outcome, 'Distinct findings that must not be applied.', $question),
    ]);
    $checks->{$field} = str_repeat('d', 40);

    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands[0]['message']['text'])->toContain(TaskScheduler::WorkspaceChangedReminder)
        ->and($dispatcher->commands[0]['message']['text'])->not->toContain('Distinct findings')
        ->and($task->comments()->sole()->getRawOriginal('type'))->toBe($outcome);
})->with([
    'changes requested' => ['changes_requested', null, 'tree'],
    'blocked' => ['blocked', 'Which contract should win?', 'head'],
]);

it('treats a newer implementer turn during review as a new handoff without reminding the reviewer', function (): void {
    [$group, $task, , $signer, $checks, $dispatcher, $reader] = scheduler_review([
        FakeTaskRunReceipts::contents('approved', 'Checked the models.'),
        null,
        FakeTaskRunReceipts::contents('ready_for_review', 'Operator follow-up.'),
    ]);
    $task->update(['completion_handoff_turn_id' => 'implementer-handoff']);
    $reader->implementerTurnId = 'implementer-operator';
    $reader->implementerState = 'running';
    $checks->tree = str_repeat('c', 40);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing)
        ->and($dispatcher->commands)->toBe([])
        ->and($signer->messages)->toBe([])
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->comments()->sole()->commit_sha)->toBeNull();

    $reader->implementerState = 'completed';
    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($dispatcher->commands)->toBe([])
        ->and($signer->messages)->toBe([])
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->review_attempt)->toBe($task->review_attempt + 1)
        ->and($task->comments()->sole()->commit_sha)->toBeNull();

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and(TaskCheck::query()->where('task_id', $task->id)->sole()->status)->toBe(TaskCheckStatus::Running)
        ->and($dispatcher->commands)->toBe([]);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->review_notified_attempt)->toBe($task->fresh()?->review_attempt)
        ->and($task->fresh()?->review_workspace_head)->toBe($checks->head)
        ->and($task->fresh()?->review_workspace_tree)->toBe(str_repeat('c', 40))
        ->and($dispatcher->commands)->toBe([])
        ->and($signer->messages)->toBe([]);
});

it('does not remind the reviewer when a newer implementer turn explains a changes or blocked receipt', function (string $outcome, ?string $question): void {
    [$group, $task, , $signer, $checks, $dispatcher, $reader] = scheduler_review([
        FakeTaskRunReceipts::contents($outcome, 'Distinct findings that must not be applied.', $question),
    ]);
    $task->update(['completion_handoff_turn_id' => 'implementer-handoff']);
    $reader->implementerTurnId = 'implementer-operator';
    $checks->tree = str_repeat('c', 40);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($dispatcher->commands)->toBe([])
        ->and($signer->messages)->toBe([])
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->comments()->sole()->getRawOriginal('type'))->toBe($outcome);
})->with([
    'changes requested' => ['changes_requested', null],
    'blocked' => ['blocked', 'Which contract should win?'],
]);

it('still reminds the reviewer when the implementer turn is the handoff turn', function (): void {
    [, $task, , $signer, $checks, $dispatcher, $reader] = scheduler_review([
        FakeTaskRunReceipts::contents('approved', 'Checked the models.'),
    ]);
    $task->update(['completion_handoff_turn_id' => 'review-turn']);
    $reader->implementerTurnId = 'review-turn';
    $checks->tree = str_repeat('c', 40);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($signer->messages)->toBe([])
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toContain(TaskScheduler::WorkspaceChangedReminder);
});

it('accepts a lost commit response as the stored commit without a reminder or a second commit', function (): void {
    [$group, $task, , , $checks, $dispatcher] = scheduler_review([
        FakeTaskRunReceipts::contents('approved', 'Checked the models.'),
    ]);
    $stored = str_repeat('d', 40);
    $checks->head = $stored;
    $checks->parent = str_repeat('a', 40);
    $checks->commitTree = str_repeat('b', 40);
    $signer = new class implements TaskWorkspaceSigner
    {
        public int $calls = 0;

        public function commit(AppInstance $instance, string $message): ?string
        {
            $this->calls++;

            return str_repeat('e', 40);
        }
    };
    $publisher = new class implements TaskPullRequestPublisher
    {
        /** @var list<string> */
        public array $commits = [];

        public int $pushFailures = 1;

        public function publish(TaskGroup $group, string $body, string $commit): string
        {
            return 'https://github.com/acme/orbit/pull/1';
        }

        public function push(TaskGroup $group, string $commit): void
        {
            $this->commits[] = $commit;
            if ($this->pushFailures > 0) {
                $this->pushFailures--;

                throw new TaskPullRequestException('The task branch could not be pushed.');
            }
        }
    };
    app()->instance(TaskWorkspaceSigner::class, $signer);
    app()->instance(TaskPullRequestPublisher::class, $publisher);

    app(TaskScheduler::class)->tick();

    expect($signer->calls)->toBe(0)
        ->and($dispatcher->commands)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->comments()->sole()->commit_sha)->toBe($stored)
        ->and($publisher->commits)->toBe([$stored]);

    app(TaskScheduler::class)->tick();

    expect($signer->calls)->toBe(0)
        ->and($dispatcher->commands)->toBe([])
        ->and($publisher->commits)->toBe([$stored, $stored])
        ->and($task->comments()->count())->toBe(1)
        ->and($task->comments()->sole()->commit_sha)->toBe($stored)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running);
});

it('stores the commit when its response is lost and pushes that stored commit without committing again', function (): void {
    [$group, $task, , , $checks, $dispatcher] = scheduler_review([
        FakeTaskRunReceipts::contents('approved', 'Checked the models.'),
    ]);
    $stored = str_repeat('d', 40);
    $signer = new class($stored) implements TaskWorkspaceSigner
    {
        public int $calls = 0;

        public function __construct(private string $sha) {}

        public function commit(AppInstance $instance, string $message): ?string
        {
            $this->calls++;
            $checks = app(TaskCheckRunner::class);
            if ($checks instanceof FakeTaskCheckRunner) {
                $checks->head = $this->sha;
                $checks->parent = str_repeat('a', 40);
                $checks->commitTree = str_repeat('b', 40);
            }

            return null;
        }
    };
    $publisher = new class implements TaskPullRequestPublisher
    {
        /** @var list<string> */
        public array $commits = [];

        public function publish(TaskGroup $group, string $body, string $commit): string
        {
            return 'https://github.com/acme/orbit/pull/1';
        }

        public function push(TaskGroup $group, string $commit): void
        {
            $this->commits[] = $commit;
        }
    };
    app()->instance(TaskWorkspaceSigner::class, $signer);
    app()->instance(TaskPullRequestPublisher::class, $publisher);

    app(TaskScheduler::class)->tick();

    expect($signer->calls)->toBe(1)
        ->and($dispatcher->commands)->toBe([])
        ->and($task->comments()->sole()->commit_sha)->toBe($stored)
        ->and($publisher->commits)->toBe([$stored])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and($task->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($signer->calls)->toBe(1)
        ->and($publisher->commits)->toBe([$stored])
        ->and($task->comments()->count())->toBe(1);
});

it('refuses a workspace commit that is not the stored commit recorded for review', function (): void {
    [, $task, , $signer, $checks, $dispatcher] = scheduler_review([
        FakeTaskRunReceipts::contents('approved', 'Checked the models.'),
    ]);
    $checks->head = str_repeat('d', 40);
    $checks->parent = str_repeat('a', 40);
    $checks->commitTree = str_repeat('e', 40);

    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toBe([])
        ->and($task->comments()->sole()->commit_sha)->toBeNull()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toContain(TaskScheduler::WorkspaceChangedReminder);
});
