<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskConcurrencyGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;

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

it('returns a group to todo when provisioning throws and continues the tick with the next group', function (): void {
    Exceptions::fake();
    claim_hol_enable();
    $app = claim_hol_app();
    $failing = claim_hol_group($app, 'Throwing');
    $next = claim_hol_group($app, 'Next');
    $provisioning = new class($failing->id, $app) implements InstanceProvisioning
    {
        /** @var array<int, int> */
        public array $calls = [];

        public function __construct(private int $failingId, private OrbitApp $app) {}

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
        {
            $this->calls[$intent->group->id] = ($this->calls[$intent->group->id] ?? 0) + 1;
            if ($intent->group->id === $this->failingId) {
                throw new RuntimeException('Lock wait timeout for token secret-value-123');
            }

            return claim_hol_instance($this->app, 'claim-hol-'.$intent->group->id);
        }
    };
    app()->instance(InstanceProvisioning::class, $provisioning);
    claim_hol_spawner();

    $this->artisan('tasks:tick')->assertSuccessful();

    Exceptions::assertReported(RuntimeException::class);
    expect($provisioning->calls[$failing->id])->toBe(1)
        ->and($failing->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and($failing->fresh()?->assistance_reason)->toBe(TaskScheduler::ProvisioningFailedReason)
        ->and($next->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and(TaskGroup::query()->where('status', TaskGroupStatus::Reserved)->count())->toBe(0)
        ->and(app(TaskConcurrencyGuard::class)->activeForApp($app->id))->toBe(1);
});

it('clears the provisioning failure reason when a group moves to backlog', function (): void {
    $gateway = claim_hol_enable();
    $group = claim_hol_group(claim_hol_app(), 'Moved');
    $group->update(['assistance_reason' => TaskScheduler::ProvisioningFailedReason]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);

    $this->patchJson("/api/v1/task-groups/{$group->id}", ['status' => 'backlog'])->assertOk();

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Backlog)
        ->and($group->fresh()?->assistance_reason)->toBeNull();
});

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

describe('a start that fails after provisioning', function (): void {
    it('returns the group to todo with its Instance and a fixed reason, then continues with the next group', function (): void {
        Exceptions::fake();
        claim_hol_enable();
        $app = claim_hol_app();
        $failing = claim_hol_group($app, 'Busy');
        $next = claim_hol_group($app, 'Next');
        $provisioning = claim_hol_recording_provisioning($app);
        app()->instance(InstanceProvisioning::class, $provisioning);
        claim_hol_spawner();
        claim_hol_fail_first_start($failing->id);

        $this->artisan('tasks:tick')->assertSuccessful();

        Exceptions::assertReported(DeadlockException::class);
        $group = $failing->fresh(['taskable']);
        expect($group?->status)->toBe(TaskGroupStatus::Todo)
            ->and($group?->assistance_reason)->toBe(TaskScheduler::StartFailedReason)
            ->and($group?->taskable?->is($provisioning->instances[$failing->id]))->toBeTrue()
            ->and($next->fresh()?->status)->toBe(TaskGroupStatus::Running)
            ->and(TaskGroup::query()->where('status', TaskGroupStatus::Reserved)->count())->toBe(0)
            ->and(app(TaskConcurrencyGuard::class)->activeForNode($provisioning->instances[$failing->id]->node_id))->toBe(1);
    });

    it('reuses the kept Instance on the next claim and clears the reason', function (): void {
        Exceptions::fake();
        claim_hol_enable();
        $app = claim_hol_app();
        $group = claim_hol_group($app, 'Retry');
        $provisioning = claim_hol_recording_provisioning($app);
        app()->instance(InstanceProvisioning::class, $provisioning);
        claim_hol_spawner();
        claim_hol_fail_first_start($group->id);

        expect(app(TaskScheduler::class)->claimNext())->toBeNull();
        $claimed = app(TaskScheduler::class)->claimNext();

        expect($claimed?->status)->toBe(TaskGroupStatus::Running)
            ->and($claimed?->assistance_reason)->toBeNull()
            ->and($provisioning->attached)->toBe([null, $provisioning->instances[$group->id]->id])
            ->and(AppInstance::query()->count())->toBe(1);
    });
});

describe('the stale reservation sweep', function (): void {
    it('returns a group stranded in reserved past the bound to todo and claims it again', function (): void {
        claim_hol_enable();
        $app = claim_hol_app();
        $stranded = claim_hol_group($app, 'Stranded');
        $stranded->forceFill(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now()->subSeconds(3601)])->save();
        app()->instance(InstanceProvisioning::class, claim_hol_recording_provisioning($app));
        claim_hol_spawner();

        expect(app(TaskScheduler::class)->releaseStaleReservations())->toBe(1)
            ->and($stranded->fresh()?->status)->toBe(TaskGroupStatus::Todo)
            ->and($stranded->fresh()?->assistance_reason)->toBe(TaskScheduler::ReservationExpiredReason);

        $stranded->forceFill(['status' => TaskGroupStatus::Reserved])->save();
        $this->artisan('tasks:tick')->assertSuccessful();

        expect($stranded->fresh()?->status)->toBe(TaskGroupStatus::Running)
            ->and($stranded->fresh()?->assistance_reason)->toBeNull();
    });

    it('leaves a group reserved within the bound', function (): void {
        claim_hol_enable();
        $fresh = claim_hol_group(claim_hol_app(), 'Provisioning');
        $fresh->forceFill(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now()->subSeconds(3599)])->save();

        expect(app(TaskScheduler::class)->releaseStaleReservations())->toBe(0)
            ->and($fresh->fresh()?->status)->toBe(TaskGroupStatus::Reserved)
            ->and($fresh->fresh()?->assistance_reason)->toBeNull();
    });

    it('keeps a swept group in todo with its Instance when the late provision finishes', function (): void {
        claim_hol_enable();
        $app = claim_hol_app();
        $slow = claim_hol_group($app, 'Slow');
        $instance = claim_hol_instance($app, 'claim-hol-slow');
        app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
        {
            public function __construct(private AppInstance $instance) {}

            public function provision(InstanceProvisionIntent $intent): ?AppInstance
            {
                test()->travel(3601)->seconds();
                app(TaskScheduler::class)->releaseStaleReservations();

                return $this->instance;
            }
        });
        claim_hol_spawner();

        expect(app(TaskScheduler::class)->claimNext())->toBeNull();

        $group = $slow->fresh(['taskable', 'tasks']);
        expect($group?->status)->toBe(TaskGroupStatus::Todo)
            ->and($group?->assistance_reason)->toBe(TaskScheduler::ReservationExpiredReason)
            ->and($group?->taskable?->is($instance))->toBeTrue()
            ->and($group?->started_at)->toBeNull()
            ->and($group?->tasks->sole()->status)->toBe(TaskStatus::Todo);
    });
});

describe('a group cancelled while its claim runs', function (): void {
    it('removes the Instance the claim provisioned and keeps the group cancelled', function (): void {
        claim_hol_enable();
        $app = claim_hol_app();
        $group = claim_hol_group($app, 'Cancelled mid-claim');
        $removed = claim_hol_recording_remover();
        $instance = claim_hol_instance($app, 'task-'.$group->id);
        app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
        {
            public function __construct(private AppInstance $instance) {}

            public function provision(InstanceProvisionIntent $intent): ?AppInstance
            {
                app(CancelTaskGroupAction::class)->execute($intent->group);

                return $this->instance;
            }
        });
        claim_hol_spawner();

        expect(app(TaskScheduler::class)->claimNext())->toBeNull()
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($group->fresh()?->taskable_id)->toBeNull()
            ->and($removed->ids)->toBe([$instance->id])
            ->and(AppInstance::query()->find($instance->id))->toBeNull();
    });

    it('removes a half-provisioned workspace and does not return the group to todo when provisioning fails', function (): void {
        Exceptions::fake();
        claim_hol_enable();
        $app = claim_hol_app();
        $group = claim_hol_group($app, 'Cancelled then failed');
        $removed = claim_hol_recording_remover();
        app()->instance(InstanceProvisioning::class, new class($app) implements InstanceProvisioning
        {
            public function __construct(private OrbitApp $app) {}

            public function provision(InstanceProvisionIntent $intent): ?AppInstance
            {
                $name = 'task-'.$intent->group->id;
                claim_hol_instance($this->app, $name)->update(['branch_override' => $name]);
                app(CancelTaskGroupAction::class)->execute($intent->group);

                throw new RuntimeException('The checkout failed part way.');
            }
        });
        claim_hol_spawner();

        expect(app(TaskScheduler::class)->claimNext())->toBeNull()
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($group->fresh()?->assistance_reason)->toBeNull()
            ->and($removed->ids)->toHaveCount(1)
            ->and(AppInstance::query()->where('name', 'task-'.$group->id)->exists())->toBeFalse();
    });
});

/** Records each removal and deletes the row, as a completed removal does. */
function claim_hol_recording_remover(): object
{
    $remover = new class implements AppInstanceRemover
    {
        /** @var list<int> */
        public array $ids = [];

        public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
        {
            $this->ids[] = $instance->id;
            $instance->delete();

            return new AppInstanceRemoval;
        }
    };
    app()->instance(AppInstanceRemover::class, $remover);

    return $remover;
}

/**
 * Provisions one Instance per group and returns the Instance a group already holds, as TaskWorkspaceProvisioner does.
 */
function claim_hol_recording_provisioning(OrbitApp $app): object
{
    return new class($app) implements InstanceProvisioning
    {
        /** @var array<int, AppInstance> */
        public array $instances = [];

        /** @var list<int|null> */
        public array $attached = [];

        public function __construct(private OrbitApp $app) {}

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
        {
            $held = $intent->group->taskable;
            $this->attached[] = $held instanceof AppInstance ? $held->id : null;

            return $this->instances[$intent->group->id] = $held instanceof AppInstance
                ? $held
                : claim_hol_instance($this->app, 'claim-hol-'.$intent->group->id);
        }
    };
}

/** Makes the first move of the group to running fail the way a busy SQLite database does. */
function claim_hol_fail_first_start(int $groupId): void
{
    $failed = false;
    TaskGroup::saving(static function (TaskGroup $group) use ($groupId, &$failed): void {
        if ($failed || $group->id !== $groupId || $group->status !== TaskGroupStatus::Running) {
            return;
        }
        $failed = true;

        throw new QueryException('sqlite', 'update "task_groups" set "status" = ?', ['running'], new PDOException('database is locked'));
    });
}
