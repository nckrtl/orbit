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
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\LocalTaskSettleMetricsCollector;
use App\Domain\Tasks\NullCoderSettleNotifier;
use App\Domain\Tasks\NullTaskPullRequestOpener;
use App\Domain\Tasks\NullTaskWorkspaceDiffReader;
use App\Domain\Tasks\T3Dispatcher;
use App\Domain\Tasks\TaskCeilings;
use App\Domain\Tasks\TaskConcurrencyGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestOpener;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSettleMetrics;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;

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
        'status' => TaskGroupStatus::Queued,
    ]);

    Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => "{$title} first",
        'brief' => 'First subtask',
        'status' => TaskStatus::Pending,
    ]);

    if ($instance instanceof AppInstance) {
        $group->taskable()->associate($instance);
        $group->save();
    }

    return $group->fresh(['tasks', 'taskable']) ?? $group;
}

it('reserves the oldest queued group that still fits the App ceiling', function (): void {
    $app = scheduler_app('ceiling-app');
    $first = queued_group($app, 'One');
    $second = queued_group($app, 'Two');
    $third = queued_group($app, 'Three');
    $fourth = queued_group($app, 'Four');

    $scheduler = app(TaskScheduler::class);

    expect($scheduler->claimNext()?->id)->toBe($first->id)
        ->and($first->fresh()?->status)->toBe(TaskGroupStatus::Reserved)
        ->and($scheduler->claimNext()?->id)->toBe($second->id)
        ->and($scheduler->claimNext()?->id)->toBe($third->id)
        ->and($scheduler->claimNext())->toBeNull()
        ->and($fourth->fresh()?->status)->toBe(TaskGroupStatus::Queued)
        ->and(app(TaskConcurrencyGuard::class)->activeForApp($app->id))->toBe(TaskCeilings::PerApp);
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

    expect(app(TaskScheduler::class)->claimNext()?->id)->toBe($queued->id)
        ->and($queued->fresh()?->status)->toBe(TaskGroupStatus::Reserved);
});

it('keeps a fourth group queued when three reserved groups already occupy the App', function (): void {
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
        ->and($queued->fresh()?->status)->toBe(TaskGroupStatus::Queued);
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
        ->and($queued->fresh()?->status)->toBe(TaskGroupStatus::Queued);
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
        public function spawnReviewer(TaskGroup $group): ?string
        {
            return 'reviewer-thread';
        }

        public function spawnImplementer(Task $task): ?string
        {
            return 'implementer-thread';
        }

        public function requestReview(Task $task): void {}

        public function signOff(Task $task): ?string
        {
            return 'signoff-sha';
        }
    });

    $claimed = app(TaskScheduler::class)->claimNext();

    expect($claimed)->not->toBeNull()
        ->and($claimed?->status)->toBe(TaskGroupStatus::Running)
        ->and($claimed?->taskable_id)->toBe($instance->id)
        ->and($claimed?->reviewer_thread_id)->toBe('reviewer-thread')
        ->and($claimed?->tasks->first()?->status)->toBe(TaskStatus::Running)
        ->and($claimed?->tasks->first()?->implementer_thread_id)->toBe('implementer-thread');
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

    expect($claimed?->id)->toBe($queued->id)
        ->and($claimed?->status)->toBe(TaskGroupStatus::Reserved)
        ->and($claimed?->taskable_id)->toBe($instance->id)
        ->and($claimed?->reviewer_thread_id)->toBeNull();
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

    expect($claimed?->id)->toBe($group->id)
        ->and($claimed?->status)->toBe(TaskGroupStatus::Running)
        ->and($claimed?->taskable_id)->not->toBeNull()
        ->and($claimed?->taskable)->toBeInstanceOf(AppInstance::class)
        ->and($claimed?->taskable?->status)->toBe(AppInstanceState::SourceResolved)
        ->and($claimed?->taskable?->routes()->count())->toBe(0)
        ->and($claimed?->reviewer_thread_id)->not->toBeNull()
        ->and($claimed?->tasks->first()?->status)->toBe(TaskStatus::Running)
        ->and($claimed?->tasks->first()?->implementer_thread_id)->not->toBeNull();
});

it('hands a settled subtask to the reviewer and starts the next implementer after sign-off', function (): void {
    $app = scheduler_app('handoff-app');
    $node = scheduler_node('handoff-node', '10.44.0.93');
    $instance = scheduler_instance($app, $node, 'handoff');
    $group = queued_group($app, 'Handoff', $instance);
    Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 2,
        'title' => 'Second',
        'brief' => 'Next subtask',
        'status' => TaskStatus::Pending,
    ]);
    $spawner = new class implements AgentSpawner
    {
        /** @var list<string> */
        public array $events = [];

        public function spawnReviewer(TaskGroup $group): ?string
        {
            $this->events[] = 'reviewer';

            return 'reviewer-thread';
        }

        public function spawnImplementer(Task $task): ?string
        {
            $this->events[] = 'implementer:'.$task->position;

            return 'implementer-'.$task->position;
        }

        public function requestReview(Task $task): void
        {
            $this->events[] = 'review:'.$task->position;
        }

        public function signOff(Task $task): ?string
        {
            $this->events[] = 'signoff:'.$task->position;

            return 'sha-'.$task->position;
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
    app()->instance(TaskPullRequestOpener::class, new NullTaskPullRequestOpener);
    app()->instance(TaskSettleMetricsCollector::class, new LocalTaskSettleMetricsCollector(new NullTaskWorkspaceDiffReader));
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);

    $claimed = app(TaskScheduler::class)->claimNext();
    $first = $claimed?->tasks->first();

    expect($claimed?->status)->toBe(TaskGroupStatus::Running)
        ->and($first?->status)->toBe(TaskStatus::Running)
        ->and($first?->implementer_thread_id)->toBe('implementer-1');

    $reviewing = app(TaskScheduler::class)->settleImplementer($first ?? $group->tasks->first());

    expect($reviewing->status)->toBe(TaskGroupStatus::Reviewing)
        ->and($reviewing->tasks->first()?->status)->toBe(TaskStatus::Reviewing)
        ->and($spawner->events)->toBe(['reviewer', 'implementer:1', 'review:1']);

    $advanced = app(TaskScheduler::class)->acceptReview($reviewing->tasks->first());

    expect($advanced->status)->toBe(TaskGroupStatus::Running)
        ->and($advanced->tasks->first()?->status)->toBe(TaskStatus::Completed)
        ->and($advanced->tasks->last()?->status)->toBe(TaskStatus::Running)
        ->and($advanced->tasks->last()?->implementer_thread_id)->toBe('implementer-2')
        ->and($spawner->events)->toBe(['reviewer', 'implementer:1', 'review:1', 'signoff:1', 'implementer:2']);

    $lastReview = app(TaskScheduler::class)->settleImplementer($advanced->tasks->last());
    $settled = app(TaskScheduler::class)->acceptReview($lastReview->tasks->last());

    expect($settled->status)->toBe(TaskGroupStatus::Settling)
        ->and($settled->tasks->pluck('status')->all())->toBe([
            TaskStatus::Completed,
            TaskStatus::Completed,
        ]);
});

it('opens the pull request, writes settle metrics, and notifies Coder after the last sign-off', function (): void {
    $this->freezeTime();
    $app = scheduler_app('settle-app');
    $node = scheduler_node('settle-node', '10.44.0.95');
    $instance = scheduler_instance($app, $node, 'settle');
    $group = queued_group($app, 'Settle', $instance);
    $group->notify_coder = true;
    $group->save();
    $first = $group->tasks->first();
    $first?->update(['tokens' => 40]);
    $opener = new class implements TaskPullRequestOpener
    {
        public function open(TaskGroup $group): ?string
        {
            return 'https://github.com/nckrtl/orbit/pull/543';
        }
    };
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
        public function spawnReviewer(TaskGroup $group): ?string
        {
            return 'reviewer-thread';
        }

        public function spawnImplementer(Task $task): ?string
        {
            return 'implementer-'.$task->position;
        }

        public function requestReview(Task $task): void {}

        public function signOff(Task $task): ?string
        {
            return 'sha';
        }
    });
    app()->instance(TaskPullRequestOpener::class, $opener);
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
