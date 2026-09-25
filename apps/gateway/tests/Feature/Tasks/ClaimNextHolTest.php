<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;

it('claimNext continues after provision null', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'claim-hol-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.87',
        'wireguard_ip' => '10.44.0.87',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $this->postJson('/api/v1/tasks/enable')->assertOk();

    $app = OrbitApp::query()->create([
        'name' => 'Claim HOL',
        'slug' => 'claim-hol',
        'repository_url' => 'git@example.test:claim-hol.git',
        'default_branch' => 'main',
    ]);
    $oldest = claim_hol_group($app, 'Oldest');
    $second = claim_hol_group($app, 'Second');
    $node = Node::query()->create([
        'name' => 'claim-hol-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.88',
        'wireguard_ip' => '10.44.0.88',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'claim-hol-second',
        'checkout_path' => '/tmp/claim-hol-second',
        'status' => 'reserved',
    ]);

    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public int $calls = 0;

        public function __construct(private AppInstance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
        {
            $this->calls++;

            return $this->calls === 1 ? null : $this->instance;
        }
    });
    app()->instance(AgentSpawner::class, new class implements AgentSpawner
    {
        public function spawnReviewer(Task $task): ?int
        {
            return test_agent_thread($task->taskGroup, 'claim-hol-reviewer-'.$task->task_group_id)->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->taskGroup, 'claim-hol-implementer-'.$task->task_group_id, $task)->id;
        }

        public function requestReview(Task $task): void {}
    });

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    expect($claimed?->id)->toBe($second->id)
        ->and($claimed?->status)->toBe(TaskGroupStatus::Running)
        ->and($oldest->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and($oldest->fresh()?->assistance_requested)->toBeFalse()
        ->and($oldest->fresh()?->assistance_reason)->toBe(TaskScheduler::ProvisioningFailedReason)
        ->and($second->tasks()->sole()->fresh()?->status)->toBe(TaskStatus::Running);

    $this->getJson("/api/v1/task-groups/{$oldest->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'todo')
        ->assertJsonPath('data.assistance_reason', TaskScheduler::ProvisioningFailedReason);

    $recovered = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    expect($recovered?->id)->toBe($oldest->id)
        ->and($recovered?->status)->toBe(TaskGroupStatus::Running)
        ->and($oldest->fresh()?->assistance_requested)->toBeFalse()
        ->and($oldest->fresh()?->assistance_reason)->toBeNull()
        ->and($oldest->tasks()->sole()->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($oldest->tasks()->sole()->fresh()?->implementer_agent_thread_id)->not->toBeNull();
});

it('leaves every group untouched in todo and stops claiming when the fleet is full', function (): void {
    claim_hol_enable();
    $app = claim_hol_app();
    $groups = [claim_hol_group($app, 'First'), claim_hol_group($app, 'Second'), claim_hol_group($app, 'Third')];
    $groups[1]->update(['assistance_reason' => TaskScheduler::ProvisioningFailedReason]);
    $provisioning = new class implements InstanceProvisioning
    {
        public int $calls = 0;

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
        {
            $this->calls++;

            throw new TaskCapacityException(fleetFull: true);
        }
    };
    app()->instance(InstanceProvisioning::class, $provisioning);

    $started = app(TaskScheduler::class)->claimAvailable();

    expect($started)->toBe(0)
        ->and($provisioning->calls)->toBe(1)
        ->and(array_map(static fn (TaskGroup $group): ?TaskGroupStatus => $group->fresh()?->status, $groups))->toBe([TaskGroupStatus::Todo, TaskGroupStatus::Todo, TaskGroupStatus::Todo])
        ->and($groups[0]->fresh()?->assistance_reason)->toBeNull()
        ->and($groups[1]->fresh()?->assistance_reason)->toBe(TaskScheduler::ProvisioningFailedReason)
        ->and($groups[2]->fresh()?->assistance_reason)->toBeNull();
});

it('passes a group whose eligible Nodes are full without a failure reason', function (): void {
    claim_hol_enable();
    $app = claim_hol_app();
    $waiting = claim_hol_group($app, 'Waiting');
    $waiting->update(['assistance_reason' => TaskScheduler::ProvisioningFailedReason]);
    $next = claim_hol_group($app, 'Next');
    $instance = claim_hol_instance($app, 'claim-hol-next');
    app()->instance(InstanceProvisioning::class, new class($waiting->id, $instance) implements InstanceProvisioning
    {
        public function __construct(private int $waitingId, private AppInstance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
        {
            if ($intent->group->id === $this->waitingId) {
                throw new TaskCapacityException(fleetFull: false);
            }

            return $this->instance;
        }
    });
    claim_hol_spawner();

    $claimed = app(TaskScheduler::class)->claimNext();

    expect($claimed?->id)->toBe($next->id)
        ->and($waiting->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and($waiting->fresh()?->assistance_reason)->toBeNull();
});

it('tries a failing group once per tick', function (): void {
    claim_hol_enable();
    $app = claim_hol_app();
    $failing = claim_hol_group($app, 'Failing');
    claim_hol_group($app, 'Second');
    claim_hol_group($app, 'Third');
    $provisioning = new class($failing->id, $app) implements InstanceProvisioning
    {
        /** @var array<int, int> */
        public array $calls = [];

        public function __construct(private int $failingId, private OrbitApp $app) {}

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
        {
            $this->calls[$intent->group->id] = ($this->calls[$intent->group->id] ?? 0) + 1;

            return $intent->group->id === $this->failingId ? null : claim_hol_instance($this->app, 'claim-hol-'.$intent->group->id);
        }
    };
    app()->instance(InstanceProvisioning::class, $provisioning);
    claim_hol_spawner();

    $this->artisan('tasks:tick')->assertSuccessful();

    expect($provisioning->calls[$failing->id])->toBe(1)
        ->and(count($provisioning->calls))->toBe(3)
        ->and($failing->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and($failing->fresh()?->assistance_reason)->toBe(TaskScheduler::ProvisioningFailedReason)
        ->and(TaskGroup::query()->where('status', TaskGroupStatus::Running)->count())->toBe(2);
});

it('clears the provisioning failure reason when a group moves to backlog or is cancelled', function (TaskGroupStatus $status): void {
    $gateway = claim_hol_enable();
    $group = claim_hol_group(claim_hol_app(), 'Moved');
    $group->update(['assistance_reason' => TaskScheduler::ProvisioningFailedReason]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);

    if ($status === TaskGroupStatus::Backlog) {
        $this->patchJson("/api/v1/task-groups/{$group->id}", ['status' => 'backlog'])->assertOk();
    } else {
        $this->postJson("/api/v1/task-groups/{$group->id}/cancel")->assertOk();
    }

    expect($group->fresh()?->status)->toBe($status)
        ->and($group->fresh()?->assistance_reason)->toBeNull();
})->with([
    'backlog' => [TaskGroupStatus::Backlog],
    'cancelled' => [TaskGroupStatus::Cancelled],
]);

function claim_hol_group(OrbitApp $app, string $title): TaskGroup
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
        'title' => "{$title} task",
        'brief' => 'First task.',
        'status' => TaskStatus::Todo,
    ]);

    return $group;
}

function claim_hol_enable(): Node
{
    $gateway = test()->markAsGateway(Node::query()->create([
        'name' => 'claim-hol-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.87',
        'wireguard_ip' => '10.44.0.87',
    ]));
    test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    test()->postJson('/api/v1/tasks/enable')->assertOk();

    return $gateway;
}

function claim_hol_app(): OrbitApp
{
    return OrbitApp::query()->firstOrCreate(['slug' => 'claim-hol'], [
        'name' => 'Claim HOL',
        'repository_url' => 'git@example.test:claim-hol.git',
        'default_branch' => 'main',
    ]);
}

function claim_hol_instance(OrbitApp $app, string $name): AppInstance
{
    $node = Node::query()->firstOrCreate(['name' => 'claim-hol-node'], [
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.88',
        'wireguard_ip' => '10.44.0.88',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/tmp/{$name}",
        'status' => 'reserved',
    ]);
}

function claim_hol_spawner(): void
{
    app()->instance(AgentSpawner::class, new class implements AgentSpawner
    {
        public function spawnReviewer(Task $task): ?int
        {
            return test_agent_thread($task->taskGroup, 'claim-hol-reviewer-'.$task->task_group_id)->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->taskGroup, 'claim-hol-implementer-'.$task->task_group_id, $task)->id;
        }

        public function requestReview(Task $task): void {}
    });
}
