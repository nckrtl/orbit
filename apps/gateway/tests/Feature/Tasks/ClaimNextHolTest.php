<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskConcurrencyGuard;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
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
    $this->postJson('/api/v1/extensions/tasks/enable')->assertOk();

    $project = Project::query()->create([
        'name' => 'Claim HOL',
        'slug' => 'claim-hol',
        'repository_url' => 'git@example.test:claim-hol.git',
        'default_branch' => 'main',
    ]);
    $oldest = claim_hol_group($project, 'Oldest');
    $second = claim_hol_group($project, 'Second');
    $node = Node::query()->create([
        'name' => 'claim-hol-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.88',
        'wireguard_ip' => '10.44.0.88',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'claim-hol-second',
        'checkout_path' => '/tmp/claim-hol-second',
        'status' => 'reserved',
    ]);

    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public int $calls = 0;

        public function __construct(private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            $this->calls++;

            return $this->calls === 1 ? null : $this->instance;
        }
    });
    app()->instance(AgentSpawner::class, new class implements AgentSpawner
    {
        public function spawnReviewer(Task $task): ?int
        {
            return test_agent_thread($task->parent, 'claim-hol-reviewer-'.$task->parent_id)->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->parent, 'claim-hol-implementer-'.$task->parent_id, $task)->id;
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

    expect(app(TaskScheduler::class)->claimNext())->toBeNull();
    $this->travel(1)->minutes();
    $recovered = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    expect($recovered?->id)->toBe($oldest->id)
        ->and($recovered?->status)->toBe(TaskGroupStatus::Running)
        ->and($oldest->fresh()?->assistance_requested)->toBeFalse()
        ->and($oldest->fresh()?->assistance_reason)->toBeNull()
        ->and($oldest->tasks()->sole()->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($oldest->tasks()->sole()->fresh()?->implementer_agent_thread_id)->not->toBeNull();
});

it('fences ProvisioningFailed retry state from a paused older scheduler claim', function (bool $oldSucceeds): void {
    Cache::flush();
    $this->freezeTime();
    config(['orbit.tasks.reserved_timeout_seconds' => 60]);
    $project = claim_hol_app();
    $group = claim_hol_group($project, 'Paused claim');
    $instance = claim_hol_instance($project, 'paused-claim');
    $expectedBackoff = null;
    $pause = function () use ($group, &$expectedBackoff): void {
        $this->travel(61)->seconds();
        expect(app(TaskScheduler::class)->releaseStaleReservations())->toBe(1);
        expect(app(TaskScheduler::class)->claimNext())->toBeNull();
        $expectedBackoff = Cache::get('tasks.workspace-provisioning.'.$group->id);
        expect($expectedBackoff)->toBe(['failures' => 1, 'due' => now()->addSeconds(60)->getTimestamp()]);
    };
    $provisioning = new class($instance, $pause, $oldSucceeds) implements InstanceProvisioning
    {
        public int $calls = 0;

        public function __construct(private Instance $instance, private Closure $pause, private bool $oldSucceeds) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            $this->calls++;
            if ($this->calls === 1) {
                ($this->pause)();

                return $this->oldSucceeds ? $this->instance : null;
            }

            throw new RuntimeConvergenceException('source-access', 'app-dev.source_access_failed', 'Newer claim failed.');
        }
    };
    app()->instance(InstanceProvisioning::class, $provisioning);

    expect(app(TaskScheduler::class)->claimNext())->toBeNull();

    expect(Cache::get('tasks.workspace-provisioning.'.$group->id))->toBe($expectedBackoff);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Todo)
        ->and($group->fresh()->assistance_reason)->toBe('Workspace provisioning failed: app-dev.source_access_failed.');
    $this->travel(59)->seconds();
    expect(app(TaskScheduler::class)->claimNext())->toBeNull()
        ->and($provisioning->calls)->toBe(2);
    $this->travelBack();
})->with(['old success' => true, 'old failure' => false]);

it('leaves every group untouched in todo and stops claiming when the fleet is full', function (): void {
    claim_hol_enable();
    $project = claim_hol_app();
    $groups = [claim_hol_group($project, 'First'), claim_hol_group($project, 'Second'), claim_hol_group($project, 'Third')];
    $groups[1]->update(['assistance_reason' => TaskScheduler::ProvisioningFailedReason]);
    $provisioning = new class implements InstanceProvisioning
    {
        public int $calls = 0;

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            $this->calls++;

            throw new TaskCapacityException(fleetFull: true);
        }
    };
    app()->instance(InstanceProvisioning::class, $provisioning);

    $started = app(TaskScheduler::class)->claimAvailable();

    expect($started)->toBe(0)
        ->and($provisioning->calls)->toBe(1)
        ->and(array_map(static fn (Task $group): ?TaskGroupStatus => $group->fresh()?->status, $groups))->toBe([TaskGroupStatus::Todo, TaskGroupStatus::Todo, TaskGroupStatus::Todo])
        ->and($groups[0]->fresh()?->assistance_reason)->toBeNull()
        ->and($groups[1]->fresh()?->assistance_reason)->toBe(TaskScheduler::ProvisioningFailedReason)
        ->and($groups[2]->fresh()?->assistance_reason)->toBeNull();
});

it('passes a group whose eligible Nodes are full without a failure reason', function (): void {
    claim_hol_enable();
    $project = claim_hol_app();
    $waiting = claim_hol_group($project, 'Waiting');
    $waiting->update(['assistance_reason' => TaskScheduler::ProvisioningFailedReason]);
    $next = claim_hol_group($project, 'Next');
    $instance = claim_hol_instance($project, 'claim-hol-next');
    app()->instance(InstanceProvisioning::class, new class($waiting->id, $instance) implements InstanceProvisioning
    {
        public function __construct(private int $waitingId, private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
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
    $project = claim_hol_app();
    $failing = claim_hol_group($project, 'Failing');
    claim_hol_group($project, 'Second');
    claim_hol_group($project, 'Third');
    $provisioning = new class($failing->id, $project) implements InstanceProvisioning
    {
        /** @var array<int, int> */
        public array $calls = [];

        public function __construct(private int $failingId, private Project $project) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            $this->calls[$intent->group->id] = ($this->calls[$intent->group->id] ?? 0) + 1;

            return $intent->group->id === $this->failingId ? null : claim_hol_instance($this->project, 'claim-hol-'.$intent->group->id);
        }
    };
    app()->instance(InstanceProvisioning::class, $provisioning);
    claim_hol_spawner();

    $this->artisan('tasks:tick')->assertSuccessful();

    expect($provisioning->calls[$failing->id])->toBe(1)
        ->and(count($provisioning->calls))->toBe(3)
        ->and($failing->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and($failing->fresh()?->assistance_reason)->toBe(TaskScheduler::ProvisioningFailedReason)
        ->and(Task::topLevel()->where('status', TaskGroupStatus::Running)->count())->toBe(2);
});

it('returns a group to todo when provisioning throws and continues the tick with the next group', function (): void {
    Exceptions::fake();
    claim_hol_enable();
    $project = claim_hol_app();
    $failing = claim_hol_group($project, 'Throwing');
    $next = claim_hol_group($project, 'Next');
    $provisioning = new class($failing->id, $project) implements InstanceProvisioning
    {
        /** @var array<int, int> */
        public array $calls = [];

        public function __construct(private int $failingId, private Project $project) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            $this->calls[$intent->group->id] = ($this->calls[$intent->group->id] ?? 0) + 1;
            if ($intent->group->id === $this->failingId) {
                throw new RuntimeException('Lock wait timeout for token secret-value-123');
            }

            return claim_hol_instance($this->project, 'claim-hol-'.$intent->group->id);
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
        ->and(Task::topLevel()->where('status', TaskGroupStatus::Reserved)->count())->toBe(0)
        ->and(app(TaskConcurrencyGuard::class)->activeForApp($project->id))->toBe(1);
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

function claim_hol_group(Project $project, string $title): Task
{
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => $title,
        'brief' => "{$title} brief",
        'status' => TaskGroupStatus::Todo,
    ]);
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => "{$title} task",
        'brief' => 'First task.',
        'status' => TaskStatus::Todo,
    ]);

    return $group;
}

function claim_hol_enable(): Node
{
    bind_task_node_reachability();
    $gateway = test()->markAsGateway(Node::query()->create([
        'name' => 'claim-hol-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.87',
        'wireguard_ip' => '10.44.0.87',
    ]));
    test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    test()->postJson('/api/v1/extensions/tasks/enable')->assertOk();

    return $gateway;
}

function claim_hol_app(): Project
{
    return Project::query()->firstOrCreate(['slug' => 'claim-hol'], [
        'name' => 'Claim HOL',
        'repository_url' => 'git@example.test:claim-hol.git',
        'default_branch' => 'main',
    ]);
}

function claim_hol_instance(Project $project, string $name): Instance
{
    $node = Node::query()->firstOrCreate(['name' => 'claim-hol-node'], [
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.88',
        'wireguard_ip' => '10.44.0.88',
    ]);

    return Instance::query()->create([
        'project_id' => $project->id,
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
            return test_agent_thread($task->parent, 'claim-hol-reviewer-'.$task->parent_id)->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->parent, 'claim-hol-implementer-'.$task->parent_id, $task)->id;
        }

        public function requestReview(Task $task): void {}
    });
}

describe('a start that fails after provisioning', function (): void {
    it('returns the group to todo with its Instance and a fixed reason, then continues with the next group', function (): void {
        Exceptions::fake();
        claim_hol_enable();
        $project = claim_hol_app();
        $failing = claim_hol_group($project, 'Busy');
        $next = claim_hol_group($project, 'Next');
        $provisioning = claim_hol_recording_provisioning($project);
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
            ->and(Task::topLevel()->where('status', TaskGroupStatus::Reserved)->count())->toBe(0)
            ->and(app(TaskConcurrencyGuard::class)->activeForNode($provisioning->instances[$failing->id]->node_id))->toBe(1);
    });

    it('reuses the kept Instance on the next claim and clears the reason', function (): void {
        Exceptions::fake();
        claim_hol_enable();
        $project = claim_hol_app();
        $group = claim_hol_group($project, 'Retry');
        $provisioning = claim_hol_recording_provisioning($project);
        app()->instance(InstanceProvisioning::class, $provisioning);
        claim_hol_spawner();
        claim_hol_fail_first_start($group->id);

        expect(app(TaskScheduler::class)->claimNext())->toBeNull();
        $claimed = app(TaskScheduler::class)->claimNext();

        expect($claimed?->status)->toBe(TaskGroupStatus::Running)
            ->and($claimed?->assistance_reason)->toBeNull()
            ->and($provisioning->attached)->toBe([null, $provisioning->instances[$group->id]->id])
            ->and(Instance::query()->count())->toBe(1);
    });
});

describe('the stale reservation sweep', function (): void {
    beforeEach(function (): void {
        $this->freezeTime();
    });

    it('returns a group stranded in reserved past the bound to todo and claims it again', function (): void {
        claim_hol_enable();
        $project = claim_hol_app();
        $stranded = claim_hol_group($project, 'Stranded');
        $stranded->forceFill(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now()->subSeconds(3601)])->save();
        app()->instance(InstanceProvisioning::class, claim_hol_recording_provisioning($project));
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
        $this->freezeTime();
        claim_hol_enable();
        $fresh = claim_hol_group(claim_hol_app(), 'Provisioning');
        $fresh->forceFill(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now()->subSeconds(3599)])->save();

        expect(app(TaskScheduler::class)->releaseStaleReservations())->toBe(0)
            ->and($fresh->fresh()?->status)->toBe(TaskGroupStatus::Reserved)
            ->and($fresh->fresh()?->assistance_reason)->toBeNull();
    });

    it('keeps a swept group in todo with its Instance when the late provision finishes', function (): void {
        claim_hol_enable();
        $project = claim_hol_app();
        $slow = claim_hol_group($project, 'Slow');
        $instance = claim_hol_instance($project, 'claim-hol-slow');
        app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
        {
            public function __construct(private Instance $instance) {}

            public function provision(InstanceProvisionIntent $intent): ?Instance
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
        $project = claim_hol_app();
        $group = claim_hol_group($project, 'Cancelled mid-claim');
        $removed = claim_hol_recording_remover();
        $instance = claim_hol_instance($project, 'task-'.$group->id);
        app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
        {
            public function __construct(private Instance $instance) {}

            public function provision(InstanceProvisionIntent $intent): ?Instance
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
            ->and(Instance::query()->find($instance->id))->toBeNull();
    });

    it('removes a half-provisioned workspace and does not return the group to todo when provisioning fails', function (): void {
        Exceptions::fake();
        claim_hol_enable();
        $project = claim_hol_app();
        $group = claim_hol_group($project, 'Cancelled then failed');
        $removed = claim_hol_recording_remover();
        app()->instance(InstanceProvisioning::class, new class($project) implements InstanceProvisioning
        {
            public function __construct(private Project $project) {}

            public function provision(InstanceProvisionIntent $intent): ?Instance
            {
                $name = 'task-'.$intent->group->id;
                claim_hol_instance($this->project, $name)->update(['branch_override' => $name]);
                app(CancelTaskGroupAction::class)->execute($intent->group);

                throw new RuntimeException('The checkout failed part way.');
            }
        });
        claim_hol_spawner();

        expect(app(TaskScheduler::class)->claimNext())->toBeNull()
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($group->fresh()?->assistance_reason)->toBeNull()
            ->and($removed->ids)->toHaveCount(1)
            ->and(Instance::query()->where('name', 'task-'.$group->id)->exists())->toBeFalse();
    });
});

describe('the abandoned workspace sweep', function (): void {
    it('removes the workspace of a group cancelled during a claim that then stopped, once the bound passes', function (): void {
        claim_hol_enable();
        $project = claim_hol_app();
        $group = claim_hol_group($project, 'Orphaned');
        $removed = claim_hol_recording_remover();
        $workspace = claim_hol_workspace($project, $group);
        $group->forceFill(['status' => TaskGroupStatus::Cancelled, 'reserved_at' => now()->subSeconds(30)])->save();

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0)
            ->and($removed->ids)->toBe([]);

        $this->travel(3600)->seconds();
        $this->artisan('tasks:tick')->assertSuccessful();

        expect($removed->ids)->toBe([$workspace->id])
            ->and(Instance::query()->find($workspace->id))->toBeNull()
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Cancelled)
            ->and(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0);
    });

    it('keeps workspaces of live groups and Instances that only share the name', function (): void {
        claim_hol_enable();
        $project = claim_hol_app();
        $removed = claim_hol_recording_remover();
        $todo = claim_hol_group($project, 'Waiting');
        claim_hol_workspace($project, $todo);
        $cancelled = claim_hol_group($project, 'Cancelled lookalike');
        $cancelled->forceFill(['status' => TaskGroupStatus::Cancelled])->save();
        claim_hol_instance($project, 'task-'.$cancelled->id);

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0)
            ->and($removed->ids)->toBe([])
            ->and(Instance::query()->count())->toBe(2);
    });

    it('backs off failing removals per Instance so a later workspace is still removed', function (): void {
        Exceptions::fake();
        claim_hol_enable();
        $project = claim_hol_app();
        $remover = claim_hol_failing_remover();
        foreach (range(1, 5) as $index) {
            $group = claim_hol_group($project, "Failing {$index}");
            $group->forceFill(['status' => TaskGroupStatus::Cancelled])->save();
            $remover->failing[] = claim_hol_workspace($project, $group, 'source_resolved')->id;
        }
        $good = claim_hol_group($project, 'Good');
        $good->forceFill(['status' => TaskGroupStatus::Cancelled])->save();
        $goodWorkspace = claim_hol_workspace($project, $good, 'source_resolved');

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1)
            ->and(Instance::query()->find($goodWorkspace->id))->toBeNull()
            ->and(Task::topLevel()->where('assistance_requested', true)->count())->toBe(0)
            ->and(Task::topLevel()->where('assistance_reason', RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The Node is unreachable.')->count())->toBe(5);
        Exceptions::assertReportedCount(5);

        $this->travel(TaskScheduler::AbandonedWorkspaceBackoffSeconds - 1)->seconds();
        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0)
            ->and($remover->attempts)->toHaveCount(6);

        $this->travel(2)->seconds();
        app(TaskScheduler::class)->removeAbandonedWorkspaces();
        expect($remover->attempts)->toHaveCount(11);

        // The second failure doubles the delay.
        $this->travel(TaskScheduler::AbandonedWorkspaceBackoffSeconds + 1)->seconds();
        app(TaskScheduler::class)->removeAbandonedWorkspaces();
        expect($remover->attempts)->toHaveCount(11);

        $remover->failing = [];
        $this->travel(TaskScheduler::AbandonedWorkspaceBackoffSeconds)->seconds();
        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(5)
            ->and(Instance::query()->count())->toBe(0);
    });

    it('keeps sweeping and ticking when the backoff cache fails', function (): void {
        Exceptions::fake();
        Cache::extend('failing', static fn (): Repository => Cache::repository(new class extends ArrayStore
        {
            #[Override]
            public function get($key): mixed
            {
                throw new RuntimeException('The cache could not be read.');
            }

            #[Override]
            public function put($key, $value, $seconds): bool
            {
                throw new RuntimeException('The cache could not be written.');
            }

            #[Override]
            public function forget($key): bool
            {
                throw new RuntimeException('The cache could not be written.');
            }
        }));
        config(['cache.stores.failing' => ['driver' => 'failing'], 'cache.default' => 'failing']);
        claim_hol_enable();
        $project = claim_hol_app();
        $remover = claim_hol_failing_remover();
        $failing = claim_hol_group($project, 'Failing');
        $failing->forceFill(['status' => TaskGroupStatus::Cancelled])->save();
        $remover->failing[] = claim_hol_workspace($project, $failing, 'source_resolved')->id;
        $good = claim_hol_group($project, 'Good');
        $good->forceFill(['status' => TaskGroupStatus::Cancelled])->save();
        $goodWorkspace = claim_hol_workspace($project, $good, 'source_resolved');

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1)
            ->and(Instance::query()->find($goodWorkspace->id))->toBeNull()
            ->and($remover->attempts)->toHaveCount(2);

        $this->artisan('tasks:tick')->assertSuccessful();

        expect($remover->attempts)->toHaveCount(3);
    });

    it('removes an attached workspace of a cancelled group without waiting for the reservation bound', function (): void {
        claim_hol_enable();
        $project = claim_hol_app();
        $group = claim_hol_group($project, 'Attached');
        $workspace = claim_hol_workspace($project, $group, 'source_resolved');
        $group->taskable()->associate($workspace);
        $group->forceFill([
            'status' => TaskGroupStatus::Cancelled,
            'reserved_at' => now()->subSeconds(30),
            'assistance_requested' => true,
            'assistance_reason' => RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The Node is unreachable.',
        ])->save();
        $removed = claim_hol_recording_remover();

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1)
            ->and($removed->ids)->toBe([$workspace->id])
            ->and($group->fresh()?->taskable_id)->toBeNull()
            ->and($group->fresh()?->assistance_requested)->toBeFalse()
            ->and($group->fresh()?->assistance_reason)->toBeNull();
    });

    it('retries removal for a settling group whose merged pull request cleanup failed', function (): void {
        claim_hol_enable();
        $project = claim_hol_app();
        $group = claim_hol_group($project, 'Merged');
        $workspace = claim_hol_workspace($project, $group, 'source_resolved');
        $group->taskable()->associate($workspace);
        $group->forceFill([
            'status' => TaskGroupStatus::Settling,
            'pr_url' => 'https://github.com/nckrtl/orbit/pull/120',
            'assistance_requested' => true,
            'assistance_reason' => RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix.'disk full',
        ])->save();
        $removed = claim_hol_recording_remover();

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1)
            ->and($removed->ids)->toBe([$workspace->id])
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
            ->and($group->fresh()?->taskable_id)->toBeNull()
            ->and($group->fresh()?->assistance_requested)->toBeFalse()
            ->and($group->fresh()?->assistance_reason)->toBeNull();
    });

    it('does not remove the workspace of a group that is still running', function (): void {
        claim_hol_enable();
        $project = claim_hol_app();
        $group = claim_hol_group($project, 'Running');
        $workspace = claim_hol_workspace($project, $group, 'source_resolved');
        $group->taskable()->associate($workspace);
        $group->forceFill(['status' => TaskGroupStatus::Running])->save();
        $removed = claim_hol_recording_remover();

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0)
            ->and($removed->ids)->toBe([])
            ->and(Instance::query()->find($workspace->id))->not->toBeNull();
    });

    it('stops starting removals once the tick has spent its time budget', function (): void {
        claim_hol_enable();
        $project = claim_hol_app();
        $remover = claim_hol_failing_remover(secondsPerRemoval: 25);
        foreach (range(1, 4) as $index) {
            $group = claim_hol_group($project, "Slow {$index}");
            $group->forceFill(['status' => TaskGroupStatus::Cancelled])->save();
            claim_hol_workspace($project, $group, 'source_resolved');
        }

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(3)
            ->and(Instance::query()->count())->toBe(1)
            ->and(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1);
    });

    it('never sweeps a user Instance attached to a non-managed group', function (): void {
        claim_hol_enable();
        $project = claim_hol_app();
        $removed = claim_hol_recording_remover();
        $managed = claim_hol_group($project, 'Ended');
        $managed->forceFill(['status' => TaskGroupStatus::Cancelled])->save();
        $workspace = claim_hol_workspace($project, $managed, 'source_resolved');
        $managed->taskable()->associate($workspace);
        $managed->save();

        $lookalike = claim_hol_group($project, 'Name collision');
        $lookalike->forceFill(['status' => TaskGroupStatus::Cancelled])->save();
        $userInstance = claim_hol_workspace($project, $lookalike, 'source_resolved');
        $annotation = claim_hol_group($project, 'Annotation');
        $annotation->taskable()->associate($userInstance);
        $annotation->forceFill([
            'execution_mode' => TaskExecutionMode::ExistingThread,
            'status' => TaskGroupStatus::Cancelled,
        ])->save();

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1)
            ->and($removed->ids)->toBe([$workspace->id])
            ->and(Instance::query()->find($userInstance->id))->not->toBeNull()
            ->and($annotation->fresh()?->taskable_id)->toBe($userInstance->id);
    });

    it('pushes a stored approval before it deletes a cancelled workspace', function (): void {
        claim_hol_enable();
        $project = claim_hol_app();
        $group = claim_hol_group($project, 'Approved');
        $workspace = claim_hol_workspace($project, $group, 'source_resolved');
        $group->taskable()->associate($workspace);
        $group->forceFill(['status' => TaskGroupStatus::Cancelled])->save();
        $sha = str_repeat('a', 40);
        TaskComment::query()->create([
            'task_group_id' => $group->id,
            'task_id' => $group->tasks()->value('id'),
            'type' => TaskCommentType::Approved,
            'body' => 'Approved.',
            'author' => 'reviewer',
            'review_attempt' => 1,
            'commit_sha' => $sha,
            'posted_at' => now(),
        ]);
        $publisher = new class implements TaskPullRequestPublisher
        {
            /** @var list<string> */
            public array $commits = [];

            public function publish(Task $group, string $body, string $commit): string
            {
                throw new TaskPullRequestException('Cancel never opens a pull request.');
            }

            public function push(Task $group, string $commit): void
            {
                $this->commits[] = $commit;
            }
        };
        app()->instance(TaskPullRequestPublisher::class, $publisher);
        $removed = claim_hol_recording_remover();

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1)
            ->and($publisher->commits)->toBe([$sha])
            ->and($removed->ids)->toBe([$workspace->id]);

        $stuck = claim_hol_group($project, 'Unpushed');
        $stuckWorkspace = claim_hol_workspace($project, $stuck, 'source_resolved');
        $stuck->taskable()->associate($stuckWorkspace);
        $stuck->forceFill(['status' => TaskGroupStatus::Cancelled])->save();
        TaskComment::query()->create([
            'task_group_id' => $stuck->id,
            'task_id' => $stuck->tasks()->value('id'),
            'type' => TaskCommentType::Approved,
            'body' => 'Approved.',
            'author' => 'reviewer',
            'review_attempt' => 1,
            'commit_sha' => str_repeat('b', 40),
            'posted_at' => now(),
        ]);
        app()->instance(TaskPullRequestPublisher::class, new class implements TaskPullRequestPublisher
        {
            public function publish(Task $group, string $body, string $commit): string
            {
                throw new TaskPullRequestException('Cancel never opens a pull request.');
            }

            public function push(Task $group, string $commit): void
            {
                throw new TaskPullRequestException('The task branch could not be pushed.');
            }
        });

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0)
            ->and(Instance::query()->find($stuckWorkspace->id))->not->toBeNull()
            ->and($stuck->fresh()?->assistance_reason)->toBe(RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The task branch could not be pushed.')
            ->and($removed->ids)->toBe([$workspace->id]);
    });
});

/** Removes Instances, fails for the listed ids, and advances the clock by the time one removal takes. */
function claim_hol_failing_remover(int $secondsPerRemoval = 0): object
{
    $remover = new class($secondsPerRemoval) implements InstanceRemover
    {
        /** @var list<int> */
        public array $failing = [];

        /** @var list<int> */
        public array $attempts = [];

        public function __construct(private int $secondsPerRemoval) {}

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $this->attempts[] = $instance->id;
            test()->travel($this->secondsPerRemoval)->seconds();
            if (in_array($instance->id, $this->failing, true)) {
                throw new RuntimeException('The Node is unreachable.');
            }
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    return $remover;
}

/** The group's deterministic workspace, as TaskWorkspaceProvisioner creates it. */
function claim_hol_workspace(Project $project, Task $group, string $status = 'reserved'): Instance
{
    $name = 'task-'.$group->id;
    $workspace = claim_hol_instance($project, $name);
    $workspace->update(['branch_override' => $name, 'status' => $status]);

    return $workspace;
}

/** Records each removal and deletes the row, as a completed removal does. */
function claim_hol_recording_remover(): object
{
    $remover = new class implements InstanceRemover
    {
        /** @var list<int> */
        public array $ids = [];

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $this->ids[] = $instance->id;
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    return $remover;
}

/**
 * Provisions one Instance per group and returns the Instance a group already holds, as TaskWorkspaceProvisioner does.
 */
function claim_hol_recording_provisioning(Project $project): object
{
    return new class($project) implements InstanceProvisioning
    {
        /** @var array<int, Instance> */
        public array $instances = [];

        /** @var list<int|null> */
        public array $attached = [];

        public function __construct(private Project $project) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            $held = $intent->group->taskable;
            $this->attached[] = $held instanceof Instance ? $held->id : null;

            return $this->instances[$intent->group->id] = $held instanceof Instance
                ? $held
                : claim_hol_instance($this->project, 'claim-hol-'.$intent->group->id);
        }
    };
}

/** Makes the first move of the group to running fail the way a busy SQLite database does. */
function claim_hol_fail_first_start(int $groupId): void
{
    $failed = false;
    Task::saving(static function (Task $group) use ($groupId, &$failed): void {
        if ($failed || $group->id !== $groupId || $group->status !== TaskGroupStatus::Running) {
            return;
        }
        $failed = true;

        throw new QueryException('sqlite', 'update "task_groups" set "status" = ?', ['running'], new PDOException('database is locked'));
    });
}
