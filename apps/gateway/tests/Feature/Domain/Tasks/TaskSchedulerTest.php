<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCeilings;
use App\Domain\Tasks\TaskConcurrencyGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
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
