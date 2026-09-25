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
use App\Domain\Tasks\TaskCeilings;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskConcurrencyGuard;
use App\Domain\Tasks\TaskGroupMetricsRefresher;
use App\Domain\Tasks\TaskGroupStatus;
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
use App\Infrastructure\Tasks\T3\NullT3ThreadReader;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Models\AgentThread;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskGroup;
use Tests\Support\FakeTaskCheckRunner;

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

it('leaves a provisioned group reserved when the Node is already at the ceiling', function (): void {
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
        ->and($queued->fresh()?->taskable_id)->toBeNull()
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
