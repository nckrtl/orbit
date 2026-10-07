<?php

declare(strict_types=1);

use App\Actions\Tasks\ShowAgentThreadsAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Actions\Tasks\UpdateTaskGroupAction;
use App\Data\Tasks\UpdateTaskGroupData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Projects\TiaBaselineSetup;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\InstanceProvisionFailure;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\LocalTaskSettleMetricsCollector;
use App\Domain\Tasks\NullCoderSettleNotifier;
use App\Domain\Tasks\NullInstanceProvisioning;
use App\Domain\Tasks\NullTaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskBriefCoverage;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCeilings;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskConcurrencyGuard;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupMetricsRefresher;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskReviewDiffException;
use App\Domain\Tasks\TaskReviewPacket;
use App\Domain\Tasks\TaskReviewPacketBuilder;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSequenceException;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskSettleMetrics;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadObservation;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnFetchNotice;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\Tasks\TaskTurnPullRequest;
use App\Domain\Tasks\TaskTurnReceipt;
use App\Domain\Tasks\TaskTurnReceiptException;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Domain\Tasks\TaskWorkspaceTopology;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Compute\TaskSandboxDrivers;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\IncusSandboxHost;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Infrastructure\Tasks\TaskWorkspaceExecutor;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Tests\Support\AcceptingTaskWorkspaceMcp;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\AgentSnapshotReader;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;
use Tests\Support\FakeTaskWorkspaceTopology;
use Tests\Support\NullAgentSnapshotReader;
use Tests\Support\ResolvedVp;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
});

beforeEach(function (): void {
    test_bind_snapshot_driver();
});

function scheduler_app(string $slug): Project
{
    return Project::query()->create([
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

function scheduler_instance(Project $project, Node $node, string $name): Instance
{
    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/tmp/tasks-{$project->slug}-{$name}",
        'status' => 'reserved',
    ]);
}

function queued_group(Project $project, string $title, ?Instance $instance = null): Task
{
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => $title,
        'brief' => "{$title} brief",
        'status' => TaskGroupStatus::Todo,
    ]);

    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => "{$title} first",
        'brief' => 'First subtask',
        'status' => TaskStatus::Todo,
    ]);

    if ($instance instanceof Instance) {
        $group->taskable()->associate($instance);
        $group->save();
    }

    return $group->fresh(['tasks', 'taskable']) ?? $group;
}

function scheduler_pending_task(Task $group, int $position, string $title): Task
{
    return Task::query()->create([
        'parent_id' => $group->id,
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
            $group = $task->parent;

            $this->events[] = 'reviewer';

            return test_agent_thread($group, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->events[] = 'implementer:'.$task->position;

            return test_agent_thread($task->parent, 'implementer-'.$task->position, $task)->id;
        }

        public function requestReview(Task $task): void
        {
            $this->events[] = 'review:'.$task->position;
        }
    };
}

function scheduler_bind_claim(Instance $instance, AgentSpawner $spawner): void
{
    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            return $this->instance;
        }
    });
    app()->instance(AgentSpawner::class, $spawner);
    app()->instance(TaskSettleMetricsCollector::class, new LocalTaskSettleMetricsCollector(
        new TaskGroupMetricsRefresher(test_agent_observer(new NullAgentSnapshotReader), new NullTaskWorkspaceDiffReader),
    ));
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);
}

it('reserves queued groups without a per-Project ceiling', function (): void {
    $project = scheduler_app('ceiling-app');
    $first = queued_group($project, 'One');
    $second = queued_group($project, 'Two');
    $third = queued_group($project, 'Three');
    $fourth = queued_group($project, 'Four');

    $scheduler = app(TaskScheduler::class);

    expect($scheduler->claimNext())->toBeNull()
        ->and($first->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and($second->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and($third->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and($fourth->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and(app(TaskConcurrencyGuard::class)->activeForApp($project->id))->toBe(0);
});

it('does not count completed groups toward the Project ceiling', function (): void {
    $project = scheduler_app('completed-app');
    Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Done',
        'brief' => 'Already settled',
        'status' => TaskGroupStatus::Completed,
    ]);
    $queued = queued_group($project, 'Next');

    expect(app(TaskScheduler::class)->claimNext())->toBeNull()
        ->and($queued->fresh()?->status)->toBe(TaskGroupStatus::Todo);
});

it('claims another group when three reserved groups already occupy the App', function (): void {
    $project = scheduler_app('full-app');
    foreach (['A', 'B', 'C'] as $title) {
        Task::topLevel()->create([
            'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
            'project_id' => $project->id,
            'title' => $title,
            'brief' => $title,
            'status' => TaskGroupStatus::Reserved,
        ]);
    }
    $queued = queued_group($project, 'Overflow');

    expect(app(TaskScheduler::class)->claimNext())->toBeNull()
        ->and($queued->fresh()?->status)->toBe(TaskGroupStatus::Todo);
});

it('applies the Node ceiling only after a Project instance is assigned', function (): void {
    $project = scheduler_app('node-app');
    $node = scheduler_node('task-node', '10.44.0.90');
    $instance = scheduler_instance($project, $node, 'shared');

    foreach (range(1, TaskCeilings::PerNode) as $index) {
        $owner = scheduler_app("node-owner-{$index}");
        $placed = scheduler_instance($owner, $node, "slot-{$index}");
        $group = Task::topLevel()->create([
            'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
            'project_id' => $owner->id,
            'title' => "Active {$index}",
            'brief' => 'Occupies the node',
            'status' => TaskGroupStatus::Running,
        ]);
        $group->taskable()->associate($placed);
        $group->save();
    }

    $queued = queued_group($project, 'Blocked', $instance);

    expect(app(TaskConcurrencyGuard::class)->activeForNode($node->id))->toBe(TaskCeilings::PerNode)
        ->and(app(TaskScheduler::class)->claimNext())->toBeNull()
        ->and($queued->fresh()?->status)->toBe(TaskGroupStatus::Todo);
});

it('starts a group when provisioning assigns an instance under both ceilings', function (): void {
    $project = scheduler_app('orbit');
    $node = scheduler_node('orbit-node', '10.44.0.91');
    $instance = scheduler_instance($project, $node, 'isolated');
    $group = queued_group($project, 'Wire Pi');

    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            expect($intent->visitable)->toBeTrue()
                ->and($intent->group->project->slug)->toBe('orbit')
                ->and($intent->group->project->task_workspace_routed)->toBeTrue();

            return $this->instance;
        }
    });
    app()->instance(AgentSpawner::class, new class implements AgentSpawner
    {
        public function spawnReviewer(Task $task): ?int
        {
            $group = $task->parent;

            return test_agent_thread($group, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->parent, 'implementer-thread', $task)->id;
        }

        public function requestReview(Task $task): void {}
    });

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $claimed = $claimed?->fresh(['tasks', 'project', 'taskable']);

    expect($claimed)->not->toBeNull()
        ->and($claimed?->status)->toBe(TaskGroupStatus::Running)
        ->and($claimed?->taskable_id)->toBe($instance->id)
        ->and($claimed?->reviewer_agent_thread_id)->toBeNull()
        ->and($claimed?->tasks->first()?->status)->toBe(TaskStatus::Running)
        ->and($claimed?->tasks->first()?->implementer_agent_thread_id)->toBe(AgentThread::query()->where('external_id', 'implementer-thread')->sole()->id)
        ->and(app(TaskTurnReceipts::class)->prepared)->toBe(['implementer']);
});

it('fails a group and its first task when the turn command cannot be installed', function (): void {
    $project = scheduler_app('orbit');
    $node = scheduler_node('orbit-node', '10.44.0.91');
    $instance = scheduler_instance($project, $node, 'isolated');
    $group = queued_group($project, 'Wire Pi');
    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            return $this->instance;
        }
    });
    $spawner = new class implements AgentSpawner
    {
        public int $implementers = 0;

        public function spawnReviewer(Task $task): ?int
        {
            $group = $task->parent;

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
    mock(TaskTurnReceipts::class)->shouldReceive('prepare')->andThrow(new TaskTurnReceiptException('The task workspace could not be reached for the turn receipt.'));

    app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Failed)
        ->and($group->tasks()->first()?->status)->toBe(TaskStatus::Failed)
        ->and($spawner->implementers)->toBe(0);
});

it('keeps the task in review and counts a communication failure when the reviewer spawn at the first handoff returns no thread id', function (): void {
    $project = scheduler_app('missing-reviewer');
    $instance = scheduler_instance($project, scheduler_node('missing-reviewer-node', '10.44.0.96'), 'workspace');
    $group = queued_group($project, 'Missing reviewer');

    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
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
            return test_agent_thread($task->parent, 'implementer-thread', $task)->id;
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
        ->and(app(TaskTurnReceipts::class)->prepared)->toBe(['implementer', 'reviewer:final']);
});

it('fails a group and its first task when the implementer spawn returns no thread id', function (): void {
    $project = scheduler_app('missing-implementer');
    $instance = scheduler_instance($project, scheduler_node('missing-implementer-node', '10.44.0.97'), 'workspace');
    $group = queued_group($project, 'Missing implementer');

    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            return $this->instance;
        }
    });
    app()->instance(AgentSpawner::class, new class implements AgentSpawner
    {
        public function spawnReviewer(Task $task): ?int
        {
            $group = $task->parent;

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
    $claimed = $claimed?->fresh(['tasks', 'project', 'taskable']);

    expect($claimed?->status)->toBe(TaskGroupStatus::Failed)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Failed)
        ->and($group->fresh()?->reviewer_agent_thread_id)->toBeNull()
        ->and($group->tasks->first()?->fresh()?->status)->toBe(TaskStatus::Failed)
        ->and($group->tasks->first()?->fresh()?->implementer_agent_thread_id)->toBeNull();
});

it('fails the group when a later implementer spawn returns no thread id', function (): void {
    $project = scheduler_app('missing-next-implementer');
    $instance = scheduler_instance($project, scheduler_node('missing-next-node', '10.44.0.98'), 'workspace');
    $group = queued_group($project, 'Missing next implementer', $instance);
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 2,
        'title' => 'Second',
        'brief' => 'Next subtask',
        'status' => TaskStatus::Todo,
    ]);

    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            return $this->instance;
        }
    });
    app()->instance(AgentSpawner::class, new class implements AgentSpawner
    {
        public function spawnReviewer(Task $task): ?int
        {
            $group = $task->parent;

            return test_agent_thread($group, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return $task->position === 1 ? test_agent_thread($task->parent, 'implementer-1', $task)->id : null;
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
    $project = scheduler_app('held-app');
    $node = scheduler_node('full-node', '10.44.0.92');
    $instance = scheduler_instance($project, $node, 'held');

    foreach (range(1, TaskCeilings::PerNode) as $index) {
        $owner = scheduler_app("fill-owner-{$index}");
        $placed = scheduler_instance($owner, $node, "fill-{$index}");
        $group = Task::topLevel()->create([
            'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
            'project_id' => $owner->id,
            'title' => "Fill {$index}",
            'brief' => 'Fills the node',
            'status' => TaskGroupStatus::Reviewing,
        ]);
        $group->taskable()->associate($placed);
        $group->save();
    }

    $queued = queued_group($project, 'Wait');
    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
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

it('provisioning failures raise assistance with the reported checkout prepare step and message', function (): void {
    Exceptions::fake();
    $project = scheduler_app('prepare-failure');
    $project->update(['task_workspace_routed' => false]);
    $node = scheduler_node('prepare-node', '10.44.0.94');
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $group = queued_group($project, 'Prepare failure');
    $workspace = scheduler_instance($project, $node, 'task-'.$group->id);
    $workspace->update(['branch_override' => $workspace->name, 'status' => InstanceState::Reserved, 'task_workspace_routed' => false]);
    mock(DevelopmentInstanceSourceLifecycle::class)->shouldReceive('prepare')->times(3)
        ->andThrow(new RuntimeConvergenceException('app-instance-source-prepare', 'instance.path_taken', 'The checkout already exists.'));
    app()->bind(InstanceProvisioning::class, TaskWorkspaceProvisioner::class);

    for ($tick = 1; $tick <= 3; $tick++) {
        expect(app(TaskScheduler::class)->claimAvailable())->toBe(0);
        expect($group->fresh()->status)->toBe(TaskGroupStatus::Todo)
            ->and($group->fresh()->assistance_requested)->toBe($tick === 3);
    }
    expect($group->fresh()->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->fresh()->assistance_reason)->toContain('RuntimeConvergenceException', 'app-instance-source-prepare', 'The checkout already exists.');
    Exceptions::assertReported(fn (RuntimeConvergenceException $exception): bool => $exception->step === 'app-instance-source-prepare' && $exception->getMessage() === 'The checkout already exists.');

    scheduler_bind_claim($workspace, scheduler_recording_spawner());
    expect(app(TaskScheduler::class)->claimAvailable())->toBe(1)
        ->and($group->fresh()->assistance_requested)->toBeFalse()
        ->and($group->fresh()->assistance_kind)->toBeNull()
        ->and($group->fresh()->assistance_reason)->toBeNull();
});

it('provisioning failures raise assistance after three exceptions without blocking another group in the tick', function (): void {
    Exceptions::fake();
    $project = scheduler_app('throwing-provision');
    $group = queued_group($project, 'Fails');
    $node = scheduler_node('good-node', '10.44.0.95');
    $instance = scheduler_instance($project, $node, 'good');
    $second = queued_group($project, 'Starts', $instance);
    scheduler_bind_claim($instance, scheduler_recording_spawner());
    app()->instance(InstanceProvisioning::class, new class($group->id, $instance) implements InstanceProvisioning
    {
        public function __construct(private int $failingId, private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            if ($intent->group->id === $this->failingId) {
                throw new RuntimeException('node-7 unreachable');
            }

            return $this->instance;
        }
    });

    expect(app(TaskScheduler::class)->claimAvailable())->toBe(1)
        ->and($second->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()->assistance_requested)->toBeFalse();
    expect(app(TaskScheduler::class)->claimAvailable())->toBe(0)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Todo)
        ->and($group->fresh()->assistance_requested)->toBeFalse();
    expect(app(TaskScheduler::class)->claimAvailable())->toBe(0)
        ->and($group->fresh()->assistance_requested)->toBeTrue()
        ->and($group->fresh()->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->fresh()->assistance_reason)->toContain('RuntimeException', 'node-7 unreachable');
});

it('provisioning failures raise assistance only after the configured threshold and reset on a successful start', function (): void {
    $project = scheduler_app('reset-provision');
    $group = queued_group($project, 'Reset');
    $node = scheduler_node('reset-node', '10.44.0.96');
    $instance = scheduler_instance($project, $node, 'reset');
    scheduler_bind_claim($instance, scheduler_recording_spawner());
    $provisioner = new class($instance) implements InstanceProvisioning
    {
        public bool $succeed = false;

        public function __construct(private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            return $this->succeed ? $this->instance : null;
        }
    };
    app()->instance(InstanceProvisioning::class, $provisioner);
    app(TaskScheduler::class)->claimAvailable();
    app(TaskScheduler::class)->claimAvailable();
    $provisioner->succeed = true;
    expect(app(TaskScheduler::class)->claimAvailable())->toBe(1)
        ->and($group->fresh()->assistance_reason)->toBeNull();
    $group->refresh()->update(['status' => TaskGroupStatus::Todo]);
    $provisioner->succeed = false;
    app(TaskScheduler::class)->claimAvailable();
    app(TaskScheduler::class)->claimAvailable();
    expect($group->fresh()->assistance_requested)->toBeFalse();
    config(['orbit.tasks.provisioning_failure_threshold' => 5]);
    app(TaskScheduler::class)->claimAvailable();
    expect($group->fresh()->assistance_requested)->toBeFalse();
    app(TaskScheduler::class)->claimAvailable();
    app(TaskScheduler::class)->claimAvailable();
    expect($group->fresh()->assistance_requested)->toBeTrue()
        ->and($group->fresh()->assistance_reason)->toBe(TaskScheduler::ProvisioningFailedReason);
});

it('notifies Coder once after committing threshold assistance and does not resend on another failure', function (): void {
    config(['orbit.tasks.provisioning_failure_threshold' => 2]);
    $group = queued_group(scheduler_app('notify-threshold'), 'Notify threshold');
    $cause = 'RuntimeException: node-7 unreachable';
    $reason = TaskScheduler::ProvisioningFailedReason.' '.$cause;
    app()->instance(InstanceProvisioning::class, new class($cause) implements InstanceProvisioning
    {
        public function __construct(private string $cause) {}

        public function provision(InstanceProvisionIntent $intent): InstanceProvisionFailure
        {
            return new InstanceProvisionFailure($this->cause);
        }
    });
    $notifications = [];
    mock(CoderSettleNotifier::class)->shouldReceive('assistance')->once()
        ->withArgs(function (Task $notified, string $notifiedReason) use ($group, $reason, &$notifications): bool {
            expect(DB::transactionLevel())->toBe(1);
            expect($notified->fresh()->assistance_requested)->toBeTrue();
            $notifications[] = [$notified->id, $notifiedReason];

            return $notified->id === $group->id && $notifiedReason === $reason;
        });
    $scheduler = app(TaskScheduler::class);

    expect($scheduler->claimAvailable())->toBe(0);
    expect($group->fresh()->assistance_requested)->toBeFalse();
    expect($notifications)->toBeEmpty();
    DB::transaction(function () use ($scheduler, $group, &$notifications): void {
        expect($scheduler->claimAvailable())->toBe(0);
        expect($group->fresh()->assistance_requested)->toBeTrue();
        expect($notifications)->toBeEmpty();
    });
    expect($notifications)->toBe([[$group->id, $reason]]);
    expect($scheduler->claimAvailable())->toBe(0);
    expect($notifications)->toHaveCount(1);
    expect($group->fresh()->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->fresh()->assistance_reason)->toBe($reason);
    expect(Cache::get('tasks:provisioning-failures:'.$group->id)['failures'])->toBe(3);
});

it('preserves a direction hold without threshold notification when releasing a failed reservation', function (bool $newDirection): void {
    config(['orbit.tasks.provisioning_failure_threshold' => 1]);
    $group = queued_group(scheduler_app('notify-direction'), 'Direction hold');
    $direction = TaskAssistance::attributes(AssistanceKind::Direction, 'Which branch?', 'Choose a branch.');
    if (! $newDirection) {
        $group->update($direction);
    }
    $group->update(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now()]);
    $reserved = $group->fresh();
    if ($newDirection) {
        $group->update($direction);
    }
    mock(CoderSettleNotifier::class)->shouldNotReceive('assistance');

    DB::transaction(fn (): mixed => (new ReflectionMethod(TaskScheduler::class, 'releaseProvisioningFailure'))
        ->invoke(app(TaskScheduler::class), $reserved, new InstanceProvisionFailure('node-7 unreachable')));

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Todo)
        ->and($group->fresh()->assistance_requested)->toBeTrue()
        ->and($group->fresh()->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()->assistance_question)->toBe('Which branch?')
        ->and($group->fresh()->assistance_reason)->toBe('Choose a branch.');
})->with(['inherited direction' => false, 'direction added during provisioning' => true]);

it('does not notify Coder when threshold assistance rolls back', function (): void {
    config(['orbit.tasks.provisioning_failure_threshold' => 1]);
    $group = queued_group(scheduler_app('notify-rollback'), 'Rollback threshold');
    app()->instance(InstanceProvisioning::class, new NullInstanceProvisioning);
    mock(CoderSettleNotifier::class)->shouldNotReceive('assistance');

    expect(fn () => DB::transaction(function () use ($group): void {
        expect(app(TaskScheduler::class)->claimAvailable())->toBe(0);
        expect($group->fresh()->assistance_requested)->toBeTrue();
        throw new RuntimeException('Roll back threshold assistance.');
    }))->toThrow(RuntimeException::class, 'Roll back threshold assistance.');

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Todo)
        ->and($group->fresh()->assistance_requested)->toBeFalse()
        ->and($group->fresh()->assistance_kind)->toBeNull()
        ->and($group->fresh()->assistance_reason)->toBeNull();
    DB::transaction(fn (): null => null);
});

it('provisioning failures raise assistance at a minimum threshold of one', function (): void {
    config(['orbit.tasks.provisioning_failure_threshold' => 0]);
    $group = queued_group(scheduler_app('minimum-threshold'), 'Minimum');
    app()->instance(InstanceProvisioning::class, new NullInstanceProvisioning);

    expect(app(TaskScheduler::class)->claimAvailable())->toBe(0)
        ->and($group->fresh()->assistance_requested)->toBeTrue();
});

it('provisioning failures raise assistance safely when the cache cannot read or write', function (string $operation): void {
    $group = queued_group(scheduler_app('cache-failure'), 'Cache failure');
    app()->instance(InstanceProvisioning::class, new NullInstanceProvisioning);
    $key = 'tasks:provisioning-failures:'.$group->id;
    Cache::forever($key, ['failures' => 2, 'due' => 0]);
    Log::spy();
    $cache = Cache::partialMock();
    $cache->shouldReceive('get')->with($key)->andReturn(['failures' => 2, 'due' => 0])->byDefault();
    $cache->shouldReceive('forever')->with($key, Mockery::any())->andReturnTrue()->byDefault();
    if ($operation === 'read') {
        $cache->shouldReceive('get')->with($key)->once()->andThrow(new RuntimeException('cache unavailable'));
    } else {
        $cache->shouldReceive('forever')->with($key, ['failures' => 3, 'due' => 0])->once()->andThrow(new RuntimeException('cache unavailable'));
    }

    expect(app(TaskScheduler::class)->claimAvailable())->toBe(0)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Todo)
        ->and($group->fresh()->assistance_requested)->toBeFalse();
    Log::shouldHaveReceived('warning')->with('The workspace provisioning backoff could not be '.($operation === 'read' ? 'read' : 'written').'.', Mockery::any())->once();
})->with(['read', 'write']);

it('provisioning failures raise assistance only while the original reservation is held', function (): void {
    config(['orbit.tasks.provisioning_failure_threshold' => 1]);
    $group = queued_group(scheduler_app('released-reservation'), 'Released');
    app()->instance(InstanceProvisioning::class, new class implements InstanceProvisioning
    {
        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            $intent->group->update(['status' => TaskGroupStatus::Backlog, 'assistance_reason' => 'Operator moved it.']);

            return null;
        }
    });

    expect(app(TaskScheduler::class)->claimAvailable())->toBe(0)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Backlog)
        ->and($group->fresh()->assistance_requested)->toBeFalse()
        ->and($group->fresh()->assistance_reason)->toBe('Operator moved it.');
});

it('provisioning failures raise assistance that clears on a capacity wait or backlog move without erasing direction', function (string $path, bool $direction): void {
    app(TaskExtensionState::class)->enable();
    $project = scheduler_app('clear-assistance');
    $group = queued_group($project, 'Clear assistance');
    $group->tasks->first()->update(['deliverables' => [['id' => 'recovery', 'type' => 'review', 'description' => 'Verify recovery']]]);
    $instance = scheduler_instance($project, scheduler_node('clear-node', '10.44.0.98'), 'clear');
    app()->instance(InstanceProvisioning::class, new NullInstanceProvisioning);
    for ($tick = 0; $tick < 3; $tick++) {
        app(TaskScheduler::class)->claimAvailable();
    }
    expect($group->fresh()->assistance_requested)->toBeTrue();
    if ($direction) {
        TaskAssistance::apply($group, AssistanceKind::Direction, 'Which branch?', 'Choose a branch.');
    }

    if ($path === 'capacity') {
        app()->instance(InstanceProvisioning::class, new class implements InstanceProvisioning
        {
            public function provision(InstanceProvisionIntent $intent): ?Instance
            {
                throw new TaskCapacityException(fleetFull: true);
            }
        });
        expect(app(TaskScheduler::class)->claimAvailable())->toBe(0);
    } else {
        app(UpdateTaskGroupAction::class)->execute($group->fresh(), new UpdateTaskGroupData(null, null, TaskGroupStatus::Backlog));
    }
    expect($group->fresh()->assistance_requested)->toBe($direction)
        ->and($group->fresh()->assistance_kind)->toBe($direction ? AssistanceKind::Direction : null)
        ->and($group->fresh()->assistance_reason)->toBe($direction ? 'Choose a branch.' : null);

    scheduler_bind_claim($instance, scheduler_recording_spawner());
    if ($path === 'capacity') {
        expect(app(TaskScheduler::class)->claimAvailable())->toBe(1);
    } else {
        app(UpdateTaskGroupAction::class)->execute($group->fresh(), new UpdateTaskGroupData(null, null, TaskGroupStatus::Todo));
    }
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()->assistance_requested)->toBe($direction)
        ->and($group->fresh()->assistance_reason)->toBe($direction ? 'Choose a branch.' : null)
        ->and($group->fresh()->assistance_question)->toBe($direction ? 'Which branch?' : null);
})->with([
    'capacity clears failure' => ['capacity', false],
    'backlog clears failure' => ['backlog', false],
    'capacity preserves direction' => ['capacity', true],
    'backlog preserves direction' => ['backlog', true],
]);

it('provisioning failures raise assistance safely when a cache write returns false', function (int $seconds): void {
    $group = queued_group(scheduler_app('false-write'), 'False write');
    $key = 'tasks:provisioning-failures:'.$group->id;
    Log::spy();
    $cache = Cache::partialMock();
    $cache->shouldReceive('get')->with($key)->andReturn(['failures' => 2, 'due' => 0]);
    $cache->shouldReceive('forever')->with($key, ['failures' => 3, 'due' => 0])->andReturnFalse();
    app()->instance(InstanceProvisioning::class, new NullInstanceProvisioning);

    expect(app(TaskScheduler::class)->claimAvailable())->toBe(0)
        ->and($group->fresh()->assistance_requested)->toBeFalse();
    if ($seconds > 0) {
        $cache->shouldReceive('put')->with($key, ['failures' => 3, 'due' => 0], Mockery::any())->andReturnFalse();
        expect((new ReflectionMethod(TaskScheduler::class, 'rememberBackoff'))->invoke(app(TaskScheduler::class), $key, ['failures' => 3, 'due' => 0], 'workspace provisioning', $seconds))->toBeFalse();
    }
    Log::shouldHaveReceived('warning')->with('The workspace provisioning backoff could not be written.', Mockery::on(fn (array $context): bool => ($context['reason'] ?? null) === 'Cache store returned false.'))->times($seconds > 0 ? 2 : 1);
})->with(['forever' => [0], 'expiring' => [60]]);

it('provisioning failures raise assistance safely when cache cleanup returns false and distinguishes an absent key', function (bool $present): void {
    Log::spy();
    $key = 'tasks:provisioning-failures:cleanup';
    Cache::partialMock()->shouldReceive('forget')->with($key)->once()->andReturnFalse();
    Cache::shouldReceive('has')->with($key)->once()->andReturn($present);

    expect((new ReflectionMethod(TaskScheduler::class, 'rememberBackoff'))->invoke(app(TaskScheduler::class), $key, null, 'workspace provisioning'))->toBe(! $present);
    if ($present) {
        Log::shouldHaveReceived('warning')->with('The workspace provisioning backoff could not be written.', Mockery::any())->once();
    } else {
        Log::shouldNotHaveReceived('warning');
    }
})->with(['retained entry' => [true], 'already absent' => [false]]);

it('provisioning failures raise assistance using the original streak after a start transaction rolls back', function (): void {
    $project = scheduler_app('rollback-start');
    $group = queued_group($project, 'Rollback');
    $instance = scheduler_instance($project, scheduler_node('rollback-node', '10.44.0.99'), 'rollback');
    app()->instance(InstanceProvisioning::class, new NullInstanceProvisioning);
    app(TaskScheduler::class)->claimAvailable();
    app(TaskScheduler::class)->claimAvailable();
    $group->refresh()->update(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now()]);

    expect(fn () => DB::transaction(function () use ($group, $instance): void {
        expect((new ReflectionMethod(TaskScheduler::class, 'startReserved'))->invoke(app(TaskScheduler::class), $group, $instance))->toBeInstanceOf(Task::class);
        throw new RuntimeException('Injected crash before commit.');
    }))->toThrow(RuntimeException::class, 'Injected crash before commit.');
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Reserved)
        ->and(Activity::query()->where('subject_type', Task::class)->where('subject_id', $group->id)->where('description', 'Task workspace started.')->exists())->toBeFalse()
        ->and(Cache::get('tasks:provisioning-failures:'.$group->id)['failures'])->toBe(2);
    $group->refresh()->update(['status' => TaskGroupStatus::Todo]);
    app(TaskScheduler::class)->claimAvailable();
    expect($group->fresh()->assistance_requested)->toBeTrue();
});

it('provisioning failures raise assistance from a new generation after a successful start despite a crash or cleanup failure', function (string $cleanup): void {
    $project = scheduler_app('crash-after-start');
    $group = queued_group($project, 'Crash after start');
    $instance = scheduler_instance($project, scheduler_node('crash-after-node', '10.44.0.100'), 'crash-after');
    app()->instance(InstanceProvisioning::class, new NullInstanceProvisioning);
    app(TaskScheduler::class)->claimAvailable();
    app(TaskScheduler::class)->claimAvailable();
    $group->refresh()->update(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now()]);

    Log::spy();
    $key = 'tasks:provisioning-failures:'.$group->id;
    if ($cleanup === 'crash') {
        $database = Mockery::mock(DB::getFacadeRoot())->makePartial();
        $database->shouldReceive('afterCommit')->andReturnNull();
        DB::swap($database);
    } else {
        $cache = Mockery::mock(Cache::getFacadeRoot())->makePartial();
        if ($cleanup === 'false') {
            $cache->shouldReceive('forget')->with($key)->andReturnFalse();
        } else {
            $cache->shouldReceive('forget')->with($key)->andThrow(new RuntimeException('Cache cleanup unavailable.'));
        }
        Cache::swap($cache);
    }
    DB::transaction(fn (): mixed => (new ReflectionMethod(TaskScheduler::class, 'startReserved'))->invoke(app(TaskScheduler::class), $group, $instance));
    expect(Cache::get('tasks:provisioning-failures:'.$group->id)['failures'])->toBe(2);
    $firstGeneration = Activity::query()->where('subject_type', Task::class)->where('subject_id', $group->id)->where('description', 'Task workspace started.')->sole()->id;
    $group->refresh()->update(['status' => TaskGroupStatus::Todo]);
    app(TaskScheduler::class)->claimAvailable();
    app(TaskScheduler::class)->claimAvailable();
    expect($group->fresh()->assistance_requested)->toBeFalse()
        ->and(Cache::get('tasks:provisioning-failures:'.$group->id.':'.$firstGeneration)['failures'])->toBe(2);

    $group->refresh()->update(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now()]);
    DB::transaction(fn (): mixed => (new ReflectionMethod(TaskScheduler::class, 'startReserved'))->invoke(app(TaskScheduler::class), $group, $instance));
    $group->refresh()->update(['status' => TaskGroupStatus::Todo]);
    app(TaskScheduler::class)->claimAvailable();
    app(TaskScheduler::class)->claimAvailable();
    expect($group->fresh()->assistance_requested)->toBeFalse();
    if ($cleanup !== 'crash') {
        Log::shouldHaveReceived('warning')->with('The workspace provisioning backoff could not be written.', Mockery::on(fn (array $context): bool => ($context['key'] ?? null) === $key))->once();
    }
})->with(['crash', 'false', 'exception']);

it('provisioning failures raise assistance naming the driver constraint when no Node fits', function (): void {
    $project = scheduler_app('no-fit');
    $project->update(['task_workspace_routed' => false]);
    $node = scheduler_node('no-fit-node', '10.44.0.97');
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $group = queued_group($project, 'No fit');
    $driver = new FakeAgentDriver('pi');
    $driver->eligible = false;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->bind(InstanceProvisioning::class, TaskWorkspaceProvisioner::class);

    for ($tick = 0; $tick < 3; $tick++) {
        app(TaskScheduler::class)->claimAvailable();
    }
    expect($group->fresh()->assistance_requested)->toBeTrue()
        ->and($group->fresh()->assistance_reason)->toContain('driver', 'pi', 'not allowed');
});

it('advances a claimed unrouted group to running when the real provisioner and agent spawner succeed', function (): void {
    $project = scheduler_app('orbit');
    $project->update(['root' => 'public', 'task_workspace_routed' => false]);
    $node = scheduler_node('real-wire', '10.44.0.94');
    $node->update(['user' => 'orbit', 'tld' => 'test', 'settings' => ['apps' => ['path' => '/srv/orbit/apps']]]);
    $node->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $node->processes()->create([
        'name' => 'pi-server',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/home/orbit',
        'runtime_config' => ['command' => ['/home/orbit/.local/bin/pi-server', 'serve', '--port=3774']],
        'restart_policy' => 'always',
        'keep_alive' => true,
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
    $group = queued_group($project, 'Real wire');

    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    app()->instance(InstanceDestinationGuard::class, new class implements InstanceDestinationGuard
    {
        public function assertUnoccupied(Node $node, StoragePath $destination): void {}
    });
    app()->instance(DevelopmentInstanceSourceLifecycle::class, new class implements DevelopmentInstanceSourceLifecycle
    {
        public function prepare(Instance $instance, bool $allowExisting): void {}

        public function inspectPrepared(Instance $instance): void {}

        public function resolve(Instance $instance): DevelopmentSourceResolution
        {
            return new DevelopmentSourceResolution($instance->name, str_repeat('c', 40));
        }

        public function inspectResolved(Instance $instance): DevelopmentSourceResolution
        {
            return new DevelopmentSourceResolution((string) $instance->branch, (string) $instance->starting_commit);
        }
    });
    app()->instance(AgentCommandDispatcher::class, new class implements AgentCommandDispatcher
    {
        public function dispatch(Node $node, array $command): array
        {
            $threadId = is_string($command['threadId'] ?? null) ? $command['threadId'] : 'agent-thread';

            return ['sequence' => 1, 'thread_id' => $threadId];
        }
    });
    app()->instance(TaskWorkspaceSigner::class, new class implements TaskWorkspaceSigner
    {
        public function commit(Instance $instance, string $message): ?string
        {
            return str_repeat('d', 40);
        }
    });

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $claimed = $claimed?->fresh(['tasks', 'project', 'taskable']);

    expect($claimed?->id)->toBe($group->id)
        ->and($claimed?->status)->toBe(TaskGroupStatus::Running)
        ->and($claimed?->taskable_id)->not->toBeNull()
        ->and($claimed?->taskable)->toBeInstanceOf(Instance::class)
        ->and($claimed?->taskable?->status)->toBe(InstanceState::SourceResolved)
        ->and($claimed?->taskable?->task_workspace_routed)->toBeFalse()
        ->and($claimed?->taskable?->routes()->count())->toBe(0)
        ->and($claimed?->reviewer_agent_thread_id)->toBeNull()
        ->and($claimed?->tasks->first()?->status)->toBe(TaskStatus::Running)
        ->and($claimed?->tasks->first()?->implementer_agent_thread_id)->not->toBeNull();
});

it('starts only the first pending subtask when a claimed group has later siblings', function (): void {
    $project = scheduler_app('opening-order-app');
    $node = scheduler_node('opening-order-node', '10.44.0.96');
    $instance = scheduler_instance($project, $node, 'opening-order');
    $group = queued_group($project, 'Opening order', $instance);
    scheduler_pending_task($group, 2, 'Second');
    $spawner = scheduler_recording_spawner();
    scheduler_bind_claim($instance, $spawner);

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $claimed = $claimed?->fresh(['tasks', 'project', 'taskable']);
    $tasks = $claimed?->tasks->sortBy(fn (Task $task): array => [$task->position, $task->id])->values();

    expect($claimed?->status)->toBe(TaskGroupStatus::Running)
        ->and($tasks?->pluck('status')->all())->toBe([TaskStatus::Running, TaskStatus::Todo])
        ->and($tasks?->get(0)?->implementer_agent_thread_id)->toBe(AgentThread::query()->where('external_id', 'implementer-1')->sole()->id)
        ->and($tasks?->get(1)?->implementer_agent_thread_id)->toBeNull()
        ->and($spawner->events)->toBe(['implementer:1']);
});

it('rejects starting a later subtask while a sibling is still running', function (): void {
    $project = scheduler_app('second-running-app');
    $node = scheduler_node('second-running-node', '10.44.0.97');
    $instance = scheduler_instance($project, $node, 'second-running');
    $group = queued_group($project, 'Second running', $instance);
    scheduler_pending_task($group, 2, 'Second');
    $spawner = scheduler_recording_spawner();
    scheduler_bind_claim($instance, $spawner);

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $claimed = $claimed?->fresh(['tasks', 'project', 'taskable']);
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
    $project = scheduler_app('accept-next-app');
    $node = scheduler_node('accept-next-node', '10.44.0.98');
    $instance = scheduler_instance($project, $node, 'accept-next');
    $group = queued_group($project, 'Accept next', $instance);
    scheduler_pending_task($group, 2, 'Second');
    $spawner = scheduler_recording_spawner();
    scheduler_bind_claim($instance, $spawner);

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $claimed = $claimed?->fresh(['tasks', 'project', 'taskable']);
    $reviewing = app(TaskScheduler::class)->settleImplementer($claimed?->tasks->first() ?? $group->tasks->first());
    $advanced = app(TaskScheduler::class)->acceptReview($reviewing->tasks->first());
    $tasks = $advanced->tasks->sortBy(fn (Task $task): array => [$task->position, $task->id])->values();

    expect($advanced->status)->toBe(TaskGroupStatus::Running)
        ->and($tasks->pluck('status')->all())->toBe([TaskStatus::Completed, TaskStatus::Running])
        ->and($tasks->filter(fn (Task $task): bool => $task->status === TaskStatus::Running)->count())->toBe(1)
        ->and($tasks->get(1)?->implementer_agent_thread_id)->toBe(AgentThread::query()->where('external_id', 'implementer-2')->sole()->id)
        ->and($spawner->events)->toBe(['implementer:1', 'reviewer', 'implementer:2']);
});

it('starts a reviewer at each subtask handoff and starts the next implementer after approval', function (): void {
    $project = scheduler_app('handoff-app');
    $node = scheduler_node('handoff-node', '10.44.0.93');
    $instance = scheduler_instance($project, $node, 'handoff');
    $group = queued_group($project, 'Handoff', $instance);
    Task::query()->create([
        'parent_id' => $group->id,
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
            $group = $task->parent;

            $this->events[] = 'reviewer';

            return test_agent_thread($group, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->events[] = 'implementer:'.$task->position;

            return test_agent_thread($task->parent, 'implementer-'.$task->position, $task)->id;
        }

        public function requestReview(Task $task): void
        {
            $this->events[] = 'review:'.$task->position;
        }
    };

    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            return $this->instance;
        }
    });
    app()->instance(AgentSpawner::class, $spawner);
    app()->instance(TaskSettleMetricsCollector::class, new LocalTaskSettleMetricsCollector(
        new TaskGroupMetricsRefresher(test_agent_observer(new NullAgentSnapshotReader), new NullTaskWorkspaceDiffReader),
    ));
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $claimed = $claimed?->fresh(['tasks', 'project', 'taskable']);
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

    expect($spawner->events)->toBe(['implementer:1', 'reviewer', 'implementer:2', 'reviewer']);
    $settled = app(TaskScheduler::class)->acceptReview($lastReview->tasks->last());

    expect($settled->status)->toBe(TaskGroupStatus::Settling)
        ->and($settled->tasks->pluck('status')->all())->toBe([
            TaskStatus::Completed,
            TaskStatus::Completed,
        ]);
});

it('retries a review when the diff cannot be read instead of sending an empty change', function (): void {
    $project = scheduler_app('unread-diff');
    $node = scheduler_node('unread-diff-node', '10.44.0.78');
    $instance = scheduler_instance($project, $node, 'unread');
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Unread diff',
        'brief' => 'The diff read fails.',
        'status' => TaskGroupStatus::Running,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Review',
        'brief' => 'Review it.',
        'status' => TaskStatus::Running,
        'subtask_start_commit' => str_repeat('a', 40),
    ]);
    $driver = new FakeAgentDriver('pi');
    app()->instance(TaskReviewDiff::class, new class implements TaskReviewDiff
    {
        public function read(Instance $instance, string $startCommit): array
        {
            throw new TaskReviewDiffException('The review diff could not be read.');
        }
    });
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->forgetInstance(AgentSpawner::class);
    app()->forgetInstance(TaskReviewPacketBuilder::class);

    app(TaskScheduler::class)->settleImplementer($task);

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->review_notified_attempt)->toBeNull()
        ->and($task->fresh()?->communication_failures)->toBe(1)
        ->and($driver->calls)->toBe([]);
});

it('holds a review resolution when diff reads fail on a reserved reviewer and retries it', function (): void {
    $project = scheduler_app('reserved-review');
    $node = scheduler_node('reserved-review-node', '10.44.0.79');
    $instance = scheduler_instance($project, $node, 'reserved');
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Reserved review',
        'brief' => 'The diff read fails until the operator answers.',
        'status' => TaskGroupStatus::Running,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Review',
        'brief' => 'Review it.',
        'status' => TaskStatus::Running,
        'subtask_start_commit' => str_repeat('a', 40),
    ]);
    test_agent_thread($group, 'implementer-reserved', $task);
    $diff = new class implements TaskReviewDiff
    {
        public bool $fail = true;

        public function read(Instance $instance, string $startCommit): array
        {
            if ($this->fail) {
                throw new TaskReviewDiffException('The review diff could not be read.');
            }

            return [
                'files' => [],
                'diff' => '',
                'files_complete' => true,
                'diff_available' => true,
                'summary' => ['files' => 0, 'insertions' => 0, 'deletions' => 0],
            ];
        }
    };
    $driver = new FakeAgentDriver('pi');
    $driver->observation = new AgentObservation(AgentThreadState::Idle);
    app()->instance(TaskReviewDiff::class, $diff);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);
    app()->forgetInstance(AgentSpawner::class);
    app()->forgetInstance(TaskReviewPacketBuilder::class);
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->settleImplementer($task);
    for ($attempt = 0; $attempt < 6 && $task->fresh()?->assistance_requested !== true; $attempt++) {
        app(TaskScheduler::class)->tick();
    }
    $task->refresh();
    $reserved = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole();

    expect($task->assistance_requested)->toBeTrue()
        ->and($task->communication_failures)->toBeGreaterThanOrEqual(5)
        ->and($task->review_notified_attempt)->toBeNull()
        ->and($reserved->external_id)->toStartWith(TaskAgentSpawner::PendingPrefix)
        ->and($driver->calls)->toBe([]);

    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Ship the names as they are.', 'author' => 'operator',
    ]);
    $task->refresh();

    expect($driver->calls)->toBe([])
        ->and($task->assistance_requested)->toBeFalse()
        ->and($task->review_notified_attempt)->toBeNull()
        ->and($task->resolution_delivered_comment_id)->toBeNull()
        ->and($comment->review_attempt)->toBe($task->review_attempt);

    $diff->fail = false;
    app(TaskScheduler::class)->tick();
    $task->refresh();
    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole();

    expect($reviewer->external_id)->not->toStartWith(TaskAgentSpawner::PendingPrefix)
        ->and($task->review_notified_attempt)->toBe($task->review_attempt)
        ->and($task->resolution_delivered_comment_id)->toBe($comment->id)
        ->and($task->parent->reviewer_agent_thread_id)->toBe($reviewer->id)
        ->and($driver->calls[0]['operation'] ?? null)->toBe('create')
        ->and($driver->calls[0]['prompt'] ?? '')->toContain('Ship the names as they are.')
        ->and(array_column($driver->calls, 'operation'))->not->toContain('send');
});

it('reviews a subtask with a missing start commit from the previous approved commit', function (): void {
    $approved = str_repeat('e', 40);
    $starting = str_repeat('f', 40);
    [$task, $driver] = scheduler_missing_start_review($approved, $starting);

    app(TaskScheduler::class)->settleImplementer($task);
    $fresh = $task->fresh();
    $opening = $driver->calls[0]['prompt'] ?? '';

    expect($fresh?->review_notified_attempt)->toBe($fresh?->review_attempt)
        ->and($fresh?->review_notified_attempt)->not->toBeNull()
        ->and($fresh?->communication_failures)->toBe(0)
        ->and($opening)->toContain('git diff '.$approved)
        ->and($opening)->not->toContain('git diff '.$starting)
        ->and($opening)->toContain('+reviewed');
});

it('reviews the first subtask with a missing start commit from the workspace starting commit', function (): void {
    $starting = str_repeat('f', 40);
    [$task, $driver] = scheduler_missing_start_review(null, $starting);

    app(TaskScheduler::class)->settleImplementer($task);
    $fresh = $task->fresh();
    $opening = $driver->calls[0]['prompt'] ?? '';

    expect($fresh?->review_notified_attempt)->toBe($fresh?->review_attempt)
        ->and($fresh?->review_notified_attempt)->not->toBeNull()
        ->and($fresh?->communication_failures)->toBe(0)
        ->and($opening)->toContain('git diff '.$starting)
        ->and($opening)->toContain('+reviewed');
});

it('records a missing start commit on a later tick', function (): void {
    $project = scheduler_app('retry-start');
    $instance = scheduler_instance($project, scheduler_node('retry-start-node', '10.44.0.71'), 'retry');
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Retry start',
        'brief' => 'The start read failed.',
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => true,
        'assistance_reason' => 'Waiting.',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Work',
        'brief' => 'Work.',
        'status' => TaskStatus::Running,
        'assistance_requested' => true,
    ]);
    $head = str_repeat('a', 40);
    app()->instance(TaskWorkspaceStateReader::class, new class($head) implements TaskWorkspaceStateReader
    {
        public function __construct(private string $head) {}

        public function headCommit(Instance $instance): ?string
        {
            return $this->head;
        }

        public function currentBranch(Instance $instance): ?string
        {
            return 'task-retry';
        }
    });
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->subtask_start_commit)->toBe($head);
});

it('keeps a migrated continuation on its source subtask start after the source commits', function (): void {
    $project = scheduler_app('continuation-start');
    $instance = scheduler_instance($project, scheduler_node('continuation-start-node', '10.44.0.74'), 'continuation');
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Continuation start',
        'brief' => 'Overflow deliverables preserve the source boundary.',
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => true,
        'assistance_reason' => 'Waiting.',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $start = str_repeat('a', 40);
    $source = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Original task',
        'brief' => 'Implement tests and fix.',
        'status' => TaskStatus::Completed,
        'subtask_start_commit' => $start,
    ]);
    $continuation = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 2,
        'title' => 'Original task (continued 1)',
        'brief' => 'Implement tests and fix.',
        'status' => TaskStatus::Running,
        'continuation_of_task_id' => $source->id,
        'assistance_requested' => true,
    ]);
    $laterHead = str_repeat('b', 40);
    app()->instance(TaskWorkspaceStateReader::class, new class($laterHead) implements TaskWorkspaceStateReader
    {
        public function __construct(private string $head) {}

        public function headCommit(Instance $instance): ?string
        {
            return $this->head;
        }

        public function currentBranch(Instance $instance): ?string
        {
            return 'task-continuation';
        }
    });
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();

    expect($continuation->fresh()?->subtask_start_commit)->toBe($start);
});

it('does not record a later head after the implementer starts and commits', function (): void {
    $project = scheduler_app('late-start');
    $instance = scheduler_instance($project, scheduler_node('late-start-node', '10.44.0.72'), 'late');
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Late start',
        'brief' => 'The start read failed until the implementer had committed.',
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => true,
        'assistance_reason' => 'Waiting.',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Work',
        'brief' => 'Work.',
        'status' => TaskStatus::Running,
        'assistance_requested' => true,
    ]);
    $reads = 0;
    $later = str_repeat('b', 40);
    app()->instance(TaskWorkspaceStateReader::class, new class($later, $reads) implements TaskWorkspaceStateReader
    {
        public function __construct(private string $later, private int &$reads) {}

        public function headCommit(Instance $instance): ?string
        {
            $this->reads++;

            return $this->reads === 1 ? null : $this->later;
        }

        public function currentBranch(Instance $instance): ?string
        {
            return 'task-late';
        }
    });
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->subtask_start_commit)->toBeNull();

    $implementer = test_agent_thread($group, 'implementer-started', $task);
    $task->update(['implementer_agent_thread_id' => $implementer->id]);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->subtask_start_commit)->toBeNull();
});

it('records a start commit on a later tick while the implementer is only reserved', function (): void {
    $project = scheduler_app('reserved-start');
    $instance = scheduler_instance($project, scheduler_node('reserved-start-node', '10.44.0.73'), 'reserved');
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Reserved start',
        'brief' => 'The implementer row is not a turn yet.',
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => true,
        'assistance_reason' => 'Waiting.',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Work',
        'brief' => 'Work.',
        'status' => TaskStatus::Running,
        'assistance_requested' => true,
    ]);
    $later = str_repeat('c', 40);
    $reads = 0;
    app()->instance(TaskWorkspaceStateReader::class, new class($later, $reads) implements TaskWorkspaceStateReader
    {
        public function __construct(private string $later, private int &$reads) {}

        public function headCommit(Instance $instance): ?string
        {
            $this->reads++;

            return $this->reads === 1 ? null : $this->later;
        }

        public function currentBranch(Instance $instance): ?string
        {
            return 'task-reserved';
        }
    });
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();

    $reserved = test_agent_thread($group, TaskAgentSpawner::PendingPrefix.'implementer', $task);
    $task->update(['implementer_agent_thread_id' => $reserved->id]);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->subtask_start_commit)->toBe($later);
});

it('records a review-request failure and still reviews the other group', function (): void {
    Exceptions::fake();
    [, $first] = scheduler_review([], notified: false);
    [, $second] = scheduler_review([], notified: false);
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);
    $raw = 'Malformed UTF-8 characters, possibly incorrectly encoded';
    app()->instance(AgentSpawner::class, new class($first->id, $raw) implements AgentSpawner
    {
        public function __construct(private int $taskId, private string $raw) {}

        public function spawnReviewer(Task $task): ?int
        {
            if ($task->id === $this->taskId) {
                throw new RuntimeException($this->raw);
            }

            return test_agent_thread($task->parent, 'spawned-reviewer-'.$task->id)->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return null;
        }

        public function requestReview(Task $task): void {}
    });

    app(TaskScheduler::class)->tick();

    expect($first->fresh()?->communication_failures)->toBe(1)
        ->and($first->fresh()?->review_notified_attempt)->toBeNull()
        ->and($second->fresh()?->review_notified_attempt)->toBe($second->review_attempt)
        ->and($second->fresh()?->communication_failures)->toBe(0);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === $raw);

    foreach (range(1, 4) as $ignored) {
        app(TaskScheduler::class)->tick();
    }

    $reason = TaskScheduler::ReviewRequestFailedReason.' (RuntimeException).';
    expect($first->fresh()?->communication_failures)->toBe(5)
        ->and($first->fresh()?->assistance_requested)->toBeTrue()
        ->and($first->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($first->fresh()?->assistance_question)->toBeNull()
        ->and($first->fresh()?->assistance_reason)->toBe($reason)
        ->and($first->fresh()?->assistance_reason)->not->toContain($raw)
        ->and($first->parent->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($first->parent->fresh()?->assistance_question)->toBeNull()
        ->and($first->parent->fresh()?->assistance_reason)->toBe($reason)
        ->and($second->fresh()?->review_notified_attempt)->toBe($second->review_attempt)
        ->and($second->fresh()?->assistance_requested)->toBeFalse();
});

it('does not replace an open direction request when a review request keeps failing', function (): void {
    Exceptions::fake();
    [, $first] = scheduler_review([], notified: false);
    $question = 'Which reviewer should take this subtask?';
    $reason = "The reviewer is blocked: No reviewer is available.\n\nQuestion: {$question}";
    $first->update([
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
        'communication_failures' => 4,
    ]);
    $first->parent->update([
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);
    app()->instance(AgentSpawner::class, new class($first->id) implements AgentSpawner
    {
        public function __construct(private int $taskId) {}

        public function spawnReviewer(Task $task): ?int
        {
            if ($task->id === $this->taskId) {
                throw new RuntimeException('The reviewer could not be started.');
            }

            return null;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return null;
        }

        public function requestReview(Task $task): void {}
    });

    app(TaskScheduler::class)->tick();

    expect($first->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($first->fresh()?->assistance_question)->toBe($question)
        ->and($first->fresh()?->assistance_reason)->toBe($reason)
        ->and($first->parent->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($first->parent->fresh()?->assistance_question)->toBe($question)
        ->and($first->parent->fresh()?->assistance_reason)->toBe($reason);
});

/**
 * A running subtask with no recorded start commit, ready for its first review.
 *
 * @return array{Task, FakeAgentDriver}
 */
function scheduler_missing_start_review(?string $approvedCommit, string $startingCommit): array
{
    static $octet = 80;
    $octet++;
    $project = scheduler_app('missing-start-'.$octet);
    $instance = scheduler_instance($project, scheduler_node($project->slug.'-node', '10.44.3.'.$octet), 'missing');
    $instance->update(['starting_commit' => $startingCommit]);
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Missing start',
        'brief' => 'Review without a recorded start.',
        'status' => TaskGroupStatus::Running,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    if ($approvedCommit !== null) {
        $earlier = Task::query()->create([
            'parent_id' => $group->id,
            'position' => 1,
            'title' => 'Earlier',
            'brief' => 'Already approved.',
            'status' => TaskStatus::Completed,
        ]);
        TaskComment::query()->create([
            'task_group_id' => $group->id,
            'task_id' => $earlier->id,
            'type' => TaskCommentType::Approved,
            'body' => 'Approved.',
            'author' => 'reviewer',
            'commit_sha' => $approvedCommit,
            'posted_at' => now(),
        ]);
    }
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => $approvedCommit === null ? 1 : 2,
        'title' => 'Review',
        'brief' => 'Review it.',
        'status' => TaskStatus::Running,
    ]);
    $driver = new FakeAgentDriver('pi');
    app()->instance(TaskReviewDiff::class, new class implements TaskReviewDiff
    {
        public function read(Instance $instance, string $startCommit): array
        {
            if (preg_match('/\A[0-9a-f]{7,64}\z/i', $startCommit) !== 1) {
                throw new TaskReviewDiffException('The review diff could not be read.');
            }

            return [
                'files' => [['path' => 'notes.txt', 'insertions' => 1, 'deletions' => 0]],
                'diff' => "+reviewed\n",
                'files_complete' => true,
                'diff_available' => true,
                'summary' => ['files' => 1, 'insertions' => 1, 'deletions' => 0],
            ];
        }
    });
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(AgentSpawner::class, new TaskAgentSpawner(
        app(AgentDriverRegistry::class),
        new TaskReviewPacketBuilder(app(TaskReviewDiff::class)),
        app(TaskWorkspaceMcp::class),
    ));

    return [$task, $driver];
}

it('starts a fresh reviewer per subtask with the packet, and continues that thread on re-review', function (): void {
    $project = scheduler_app('fresh-reviewer');
    $project->update(['task_check' => 'composer check']);
    $node = scheduler_node('fresh-reviewer-node', '10.44.0.77');
    $instance = scheduler_instance($project, $node, 'fresh');
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Fresh reviewers',
        'brief' => 'Each subtask gets its own reviewer.',
        'status' => TaskGroupStatus::Running,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $approved = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Packet',
        'brief' => 'The packet is built.',
        'status' => TaskStatus::Completed,
    ]);
    TaskComment::query()->create([
        'task_group_id' => $group->id,
        'task_id' => $approved->id,
        'type' => TaskCommentType::Approved,
        'body' => 'The packet matches ADR 0169.',
        'author' => 'reviewer',
        'posted_at' => now(),
    ]);
    $start = str_repeat('d', 40);
    $first = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 2,
        'title' => 'First review',
        'brief' => 'Review the scheduler.',
        'status' => TaskStatus::Running,
        'subtask_start_commit' => $start,
        'deliverables' => [['id' => 'scheduler-test', 'type' => 'review', 'description' => 'Fresh reviewer per subtask']],
    ]);
    $second = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 3,
        'title' => 'Second review',
        'brief' => 'Review the next subtask.',
        'status' => TaskStatus::Todo,
        'subtask_start_commit' => $start,
    ]);
    TaskCheck::query()->create([
        'task_id' => $first->id,
        'kind' => TaskCheckKind::Handoff,
        'status' => TaskCheckStatus::Passed,
        'pid' => 1,
        'process_started' => 'Wed Sep 23 12:00:00 2026',
        'head_before' => str_repeat('a', 40),
        'tree_before' => str_repeat('b', 40),
        'exit_code' => 0,
        'started_at' => now(),
    ]);
    $driver = new FakeAgentDriver('pi');
    app()->instance(TaskReviewDiff::class, new class implements TaskReviewDiff
    {
        public function read(Instance $instance, string $startCommit): array
        {
            return [
                'files' => [['path' => 'apps/gateway/app/Domain/Tasks/TaskScheduler.php', 'insertions' => 4, 'deletions' => 1]],
                'diff' => "diff --git a/apps/gateway/app/Domain/Tasks/TaskScheduler.php\n+fresh reviewer\n",
                'files_complete' => true,
                'diff_available' => true,
                'summary' => ['files' => 1, 'insertions' => 4, 'deletions' => 1],
            ];
        }
    });
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(AgentSpawner::class, new TaskAgentSpawner(
        app(AgentDriverRegistry::class),
        new TaskReviewPacketBuilder(app(TaskReviewDiff::class)),
        app(TaskWorkspaceMcp::class),
    ));

    $reviewing = app(TaskScheduler::class)->settleImplementer($first);
    $thread = AgentThread::query()->where('task_id', $first->id)->where('role', 'reviewer')->sole();
    $opening = $driver->calls[0]['prompt'];

    expect($reviewing->fresh()?->reviewer_agent_thread_id)->toBe($thread->id)
        ->and($driver->calls[0]['title'])->toBe('Orbit task #'.$group->id.' · Review: First review')
        ->and($driver->calls[0]['operation'])->toBe('create')
        ->and($opening)->toContain('Review subtask #'.$first->id.': First review')
        ->and($opening)->toContain('Do not re-run the Project task check or deliverable commands the handoff already passed.')
        ->and($opening)->toContain('The Project task check is `composer check`.')
        ->and($opening)->toContain('Group brief')
        ->and($opening)->toContain($group->brief)
        ->and($opening)->toContain('Review the scheduler.')
        ->and($opening)->toContain('- scheduler-test (review: confirmed by the reviewer): Fresh reviewer per subtask')
        ->and($opening)->toContain('- Packet: The packet matches ADR 0169.')
        ->and($opening)->toContain('1 file changed, 4 insertions(+), 1 deletion(-)')
        ->and($opening)->toContain('`composer check` in . exited 0')
        ->and($opening)->toContain('+fresh reviewer')
        ->and($opening)->toContain('git diff '.$start)
        ->and($opening)->toContain('git diff --no-index -- /dev/null "$path" || true')
        ->and(mb_strlen($opening))->toBeLessThanOrEqual(TaskReviewPacket::Limit);

    $first->update(['status' => TaskStatus::Running, 'review_attempt' => $first->fresh()->review_attempt + 1]);
    Task::topLevel()->whereKey($group->id)->update(['status' => TaskGroupStatus::Running]);
    app(TaskScheduler::class)->settleImplementer($first->fresh(), new TaskThreadObservation(
        threadId: $thread->id,
        role: TaskThreadRole::Reviewer,
        sessState: AgentThreadState::Working->value,
        idle: false,
        pendingApprovalId: null,
        pendingUserInputId: null,
        lastAssistantText: null,
        lastUserText: null,
        hasNewCommitsSinceThreadStart: false,
        prUrl: null,
        ciSummary: null,
    ));

    expect($driver->calls)->toHaveCount(1)
        ->and($first->fresh()?->review_notified_attempt)->not->toBe($first->fresh()?->review_attempt);

    $first->update(['status' => TaskStatus::Running]);
    Task::topLevel()->whereKey($group->id)->update(['status' => TaskGroupStatus::Running]);
    app(TaskScheduler::class)->settleImplementer($first->fresh());
    $continued = $driver->calls[1]['message'];

    expect($driver->calls[1])->toMatchArray(['operation' => 'send', 'thread' => 'conversation-1'])
        ->and($continued)->toContain('Do not re-run the Project task check or deliverable commands the handoff already passed.')
        ->and($continued)->toContain('+fresh reviewer')
        ->and($continued)->toContain('git diff '.$start)
        ->and($continued)->not->toContain('Group brief')
        ->and($continued)->not->toContain('Earlier approved subtasks')
        ->and($continued)->not->toContain('Deliverables')
        ->and($group->fresh()?->reviewer_agent_thread_id)->toBe($thread->id)
        ->and(mb_strlen($continued))->toBeLessThanOrEqual(TaskReviewPacket::Limit);

    $driver->failNextSend = true;
    $first->update(['status' => TaskStatus::Running, 'review_attempt' => $first->fresh()->review_attempt + 1]);
    Task::topLevel()->whereKey($group->id)->update(['status' => TaskGroupStatus::Running]);
    app(TaskScheduler::class)->settleImplementer($first->fresh());
    $replacement = AgentThread::query()->where('task_id', $first->id)->where('role', 'reviewer')->orderByDesc('id')->first();
    $replaced = $driver->calls[3]['prompt'];

    expect($replacement?->id)->not->toBe($thread->id)
        ->and($group->fresh()?->reviewer_agent_thread_id)->toBe($replacement?->id)
        ->and($driver->calls[3]['operation'])->toBe('create')
        ->and($replaced)->toContain('Group brief')
        ->and($replaced)->toContain('Do not re-run the Project task check or deliverable commands the handoff already passed.')
        ->and(AgentThread::query()->whereKey($thread->id)->exists())->toBeTrue();

    $second->update(['status' => TaskStatus::Running]);
    Task::topLevel()->whereKey($group->id)->update(['status' => TaskGroupStatus::Running]);
    TaskCheck::query()->create([
        'task_id' => $second->id,
        'kind' => TaskCheckKind::Handoff,
        'status' => TaskCheckStatus::Passed,
        'pid' => 2,
        'process_started' => 'Wed Sep 23 12:00:01 2026',
        'head_before' => str_repeat('a', 40),
        'tree_before' => str_repeat('b', 40),
        'exit_code' => 0,
        'started_at' => now(),
    ]);
    app(TaskScheduler::class)->settleImplementer($second->fresh());
    $next = AgentThread::query()->where('task_id', $second->id)->where('role', 'reviewer')->sole();

    expect($next->id)->not->toBe($replacement?->id)
        ->and($group->fresh()?->reviewer_agent_thread_id)->toBe($next->id)
        ->and($driver->calls[4]['prompt'])->toContain('Review subtask #'.$second->id.': Second review')
        ->and($driver->calls[4]['prompt'])->toContain('Group brief')
        ->and($driver->calls[4]['prompt'])->toContain('Do not re-run the Project task check');

    app(TaskExtensionState::class)->enable();
    $agents = app(ShowAgentThreadsAction::class)->execute($group->fresh());
    $reviewers = $agents->where('role', 'reviewer')->pluck('id')->all();

    expect($reviewers)->toContain($thread->id)
        ->and($reviewers)->toContain($replacement?->id)
        ->and($reviewers)->toContain($next->id)
        ->and($agents->where('role', 'reviewer'))->toHaveCount(3);
});

it('keeps the reviewed pull request, writes settle metrics, and notifies Coder after the last sign-off', function (): void {
    $this->freezeTime();
    $project = scheduler_app('settle-app');
    $node = scheduler_node('settle-node', '10.44.0.95');
    $instance = scheduler_instance($project, $node, 'settle');
    $group = queued_group($project, 'Settle', $instance);
    $group->notify_coder = true;
    $group->pr_url = 'https://github.com/nckrtl/orbit/pull/543';
    $group->save();
    $first = $group->tasks->first();
    $first?->update(['tokens' => 40]);
    $metrics = new class implements TaskSettleMetricsCollector
    {
        public function collect(Task $group): TaskSettleMetrics
        {
            return new TaskSettleMetrics(tokens: 40, lineDiff: 12, durationMs: 1500, questions: 3, escalations: 2);
        }
    };
    $notifier = new class implements CoderSettleNotifier
    {
        public ?Task $notified = null;

        public function notify(Task $group): void
        {
            $this->notified = $group;
        }

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

        public function assistance(Task $group, string $reason): void {}
    };

    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public function __construct(private Instance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            return $this->instance;
        }
    });
    app()->instance(AgentSpawner::class, new class implements AgentSpawner
    {
        public function spawnReviewer(Task $task): ?int
        {
            $group = $task->parent;

            return test_agent_thread($group, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->parent, 'implementer-'.$task->position, $task)->id;
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
        ->and($settled->questions)->toBe(3)
        ->and($settled->escalations)->toBe(2)
        ->and($settled->settled_at)->not->toBeNull()
        ->and($notifier->notified?->id)->toBe($settled->id)
        ->and($notifier->notified?->pr_url)->toBe('https://github.com/nckrtl/orbit/pull/543');
});

it('runs the Project setup steps and check on the fresh workspace before the first implementer starts', function (): void {
    $project = scheduler_app('baseline-app');
    $project->update(['task_check' => 'composer check']);
    $instance = scheduler_instance($project, scheduler_node('baseline-node', '10.44.0.94'), 'baseline');
    $group = queued_group($project, 'Baseline', $instance);
    ProjectLifecycleStep::query()->create(['project_id' => $project->id, 'phase' => 'setup', 'name' => 'Install', 'command' => 'composer install', 'timeout_seconds' => 600, 'position' => 1]);
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
        ->and($checks->setups)->toBe([[['name' => 'Install', 'command' => 'composer install', 'timeout_seconds' => 600]]]);

    test_pass_baseline();

    expect($check->fresh()?->status)->toBe(TaskCheckStatus::Passed)
        ->and($spawner->events)->toBe(['implementer:1']);
});

it('releases the baseline claim and retries after a VP_HOME probe failure', function (): void {
    $project = scheduler_app('vp-probe-retry');
    $instance = scheduler_instance($project, scheduler_node('vp-probe-node', '10.44.0.100'), 'vp-probe');
    $group = queued_group($project, 'VP_HOME probe retry', $instance);
    $spawner = scheduler_recording_spawner();
    scheduler_bind_claim($instance, $spawner);
    $probe = new AppDevFakeSshExecutor([
        new CommandResult(42, '', '', 1, false),
        new CommandResult(0, "/opt/orbit/vite-plus/bin/vp\n", '', 1, false),
    ]);
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, json_encode([
            'pid' => 4100, 'started' => 'started', 'head' => str_repeat('a', 40), 'tree' => str_repeat('b', 40),
        ], JSON_THROW_ON_ERROR), '', 1, false),
    ]);
    app()->instance(TaskCheckRunner::class, new RemoteTaskCheckRunner(new TaskWorkspaceExecutor(
        new DevelopmentSshExecutor(
            $transport,
            app(SshKeyProvider::class),
            app(KnownHostsStore::class),
        ), app(IncusSandboxHost::class), app(TaskSandboxDrivers::class)),
        ResolvedVp::manager(probe: $probe),
        app(TiaBaselineSetup::class),
    ));

    app(TaskScheduler::class)->claimNext();

    expect(TaskCheck::query()->count())->toBe(0);
    expect($transport->commands)->toBeEmpty();
    expect($group->tasks()->firstOrFail()->communication_failures)->toBe(1);
    expect($group->fresh()?->assistance_requested)->toBeFalse();
    expect($spawner->events)->toBe([]);

    test_pass_baseline();

    expect(TaskCheck::query()->sole()->pid)->toBe(4100);
    expect($transport->commands)->toHaveCount(1);
    expect($probe->commands)->toHaveCount(2);
    expect($group->tasks()->firstOrFail()->communication_failures)->toBe(0);
    expect($group->fresh()?->assistance_requested)->toBeFalse();
});

it('runs a custom baseline command without inferring dependency installs', function (): void {
    $project = scheduler_app('custom-baseline-command');
    $project->update(['task_check' => 'composer test && bun run check']);
    $instance = scheduler_instance($project, scheduler_node('custom-baseline-node', '10.44.0.98'), 'custom-check');
    queued_group($project, 'Custom baseline command', $instance);
    scheduler_bind_claim($instance, scheduler_recording_spawner());
    $checks = new FakeTaskCheckRunner([TaskCheckReading::running()]);
    app()->instance(TaskCheckRunner::class, $checks);

    app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    expect($checks->setups)->toBe([[]])
        ->and($checks->commands)->toBe(['composer test && bun run check']);
});

it('does not infer a Composer install from the baseline command', function (string $command): void {
    $project = scheduler_app('composer-trigger');
    $project->update(['task_check' => $command]);
    $instance = scheduler_instance($project, scheduler_node('composer-trigger-node', '10.44.0.99'), 'composer-trigger');
    queued_group($project, 'Composer trigger', $instance);
    scheduler_bind_claim($instance, scheduler_recording_spawner());
    $checks = new FakeTaskCheckRunner([TaskCheckReading::running()]);
    app()->instance(TaskCheckRunner::class, $checks);

    app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    expect($checks->setups)->toBe([[]])
        ->and($checks->commands)->toBe([$command]);
})->with([
    'composer command' => ['composer test'],
    'composer after a shell operator' => ['cd app&&composer'],
    'vendor binary' => ['vendor/bin/pest'],
    'composer.json file name' => ['test -f composer.json && echo ok'],
    'composer in another word' => ['./mycomposer check'],
]);

it('passes an unset Project baseline without a command and starts the first implementer', function (): void {
    $project = scheduler_app('no-baseline-command');
    $instance = scheduler_instance($project, scheduler_node('no-baseline-command-node', '10.44.0.97'), 'no-check');
    $group = queued_group($project, 'No baseline command', $instance);
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
    $project = scheduler_app('broken-main');
    $project->update(['task_check' => 'composer check']);
    $instance = scheduler_instance($project, scheduler_node('broken-node', '10.44.0.95'), 'broken');
    $group = queued_group($project, 'Broken main', $instance);
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
 * @return array{Task, Task, object, object}
 */
function scheduler_approved_subtask(string $slug, bool $last = false, ?string $receipt = null): array
{
    $project = scheduler_app($slug);
    $instance = scheduler_instance($project, scheduler_node($slug.'-node', '10.44.0.'.$project->id), $slug);
    $group = queued_group($project, 'Push', $instance);
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
    app()->instance(AgentCommandDispatcher::class, new class implements AgentCommandDispatcher
    {
        public function dispatch(Node $node, array $command): array
        {
            return ['sequence' => 1, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    });
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => 'review-turn', 'state' => 'completed']]];
        }
    });
    app()->instance(TaskWorkspaceStateReader::class, new readonly class('task-'.$group->id) implements TaskWorkspaceStateReader
    {
        public function __construct(private string $branch) {}

        public function headCommit(Instance $instance): ?string
        {
            return null;
        }

        public function currentBranch(Instance $instance): ?string
        {
            return $this->branch;
        }
    });
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([
        $receipt ?? FakeTaskTurnReceipts::contents('approved', 'Checked the models.'),
    ]));
    $signer = new class implements TaskWorkspaceSigner
    {
        /** @var list<string> */
        public array $messages = [];

        public function commit(Instance $instance, string $message): ?string
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

        public function publish(Task $group, string $body, string $commit): string
        {
            $this->bodies[] = $body;
            $this->commits[] = $commit;

            return 'https://github.com/acme/orbit/pull/42';
        }

        public function push(Task $group, string $commit): void
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
        public function missing(Task $group, TaskTurnPullRequest $pullRequest, ?int $approvalCommentId = null, ?array $approvalChanges = null): array
        {
            return [];
        }
    });
    app()->instance(TaskSettleMetricsCollector::class, new class implements TaskSettleMetricsCollector
    {
        public function collect(Task $group): TaskSettleMetrics
        {
            return new TaskSettleMetrics(tokens: 1, lineDiff: 1, durationMs: 1);
        }
    });
    app()->instance(AgentSpawner::class, scheduler_recording_spawner());

    return [$group->fresh(['project', 'tasks', 'taskable']) ?? $group, $task, $signer, $publisher];
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
    expect($publisher->pushes)->toBe([$group->id]);

    $this->travel(TaskScheduler::retryDelaySeconds(1))->seconds();
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

    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return null;
        }
    });

    $this->travel(TaskScheduler::retryDelaySeconds(1))->seconds();
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

it('pushes an approved fixup to the existing branch, keeps the pull request, and lets settling re-evaluate it', function (): void {
    $url = 'https://github.com/acme/orbit/pull/77';
    [$group, $task, $signer, $publisher] = scheduler_approved_subtask('fixup-same-pr', last: true);
    $task->update([
        'position' => 2,
        'title' => 'Merge origin/main',
        'brief' => 'Merge origin/main into the task branch and resolve the conflicts. Do not rebase and do not force-push.',
        'fixup_problem' => 'conflict:main',
    ]);
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Models',
        'brief' => 'Store the records.',
        'status' => TaskStatus::Completed,
    ]);
    $group->update([
        'pr_url' => $url,
        'notify_coder' => true,
        'settled_at' => now()->subHour(),
        'tokens' => 3,
        'line_diff' => 4,
        'duration_ms' => 5,
    ]);
    $settledAt = $group->fresh()?->settled_at;
    $coverage = new class implements TaskBriefCoverage
    {
        public int $calls = 0;

        public function missing(Task $group, TaskTurnPullRequest $pullRequest, ?int $approvalCommentId = null, ?array $approvalChanges = null): array
        {
            $this->calls++;

            return ['Models'];
        }
    };
    $notifier = new class implements CoderSettleNotifier
    {
        public int $settled = 0;

        public function notify(Task $group): void
        {
            $this->settled++;
        }

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

        public function assistance(Task $group, string $reason): void {}
    };
    $watcher = new class implements TaskPullRequestWatcher
    {
        /** @var list<string> */
        public array $urls = [];

        public function status(Task $group): ?string
        {
            return 'open';
        }

        public function health(Task $group): ?TaskPullRequestHealth
        {
            $this->urls[] = (string) $group->pr_url;

            return new TaskPullRequestHealth(state: 'open', baseRef: 'main');
        }
    };
    app()->instance(TaskBriefCoverage::class, $coverage);
    app()->instance(TaskSettleMetricsCollector::class, new class implements TaskSettleMetricsCollector
    {
        public function collect(Task $group): TaskSettleMetrics
        {
            return new TaskSettleMetrics(tokens: 90, lineDiff: 18, durationMs: 2500);
        }
    });
    app()->instance(CoderSettleNotifier::class, $notifier);
    app()->instance(TaskPullRequestWatcher::class, $watcher);

    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toBe(["Merge origin/main\n\nChecked the models."])
        ->and($publisher->pushes)->toBe([$group->id])
        ->and($publisher->commits)->toBe([str_repeat('c', 40)])
        ->and($publisher->bodies)->toBe([])
        ->and($coverage->calls)->toBe(0)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40))
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->pr_url)->toBe($url)
        ->and($group->fresh()?->tokens)->toBe(90)
        ->and($group->fresh()?->line_diff)->toBe(18)
        ->and($group->fresh()?->duration_ms)->toBe(2500)
        ->and($group->fresh()?->settled_at?->getTimestamp())->toBe($settledAt?->getTimestamp())
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($notifier->settled)->toBe(0)
        ->and($watcher->urls)->toBe([$url])
        ->and(Task::query()->where('parent_id', $group->id)->orderBy('position')->get()->pluck('status')->all())->toBe([
            TaskStatus::Completed,
            TaskStatus::Completed,
        ]);

    app(TaskScheduler::class)->tick();

    expect($watcher->urls)->toBe([$url, $url])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->pr_url)->toBe($url)
        ->and($notifier->settled)->toBe(0)
        ->and(Task::query()->where('fixup_problem', 'conflict:main')->count())->toBe(1);
});

/** A watcher whose pull request has the given status, and whose health reports the given state and head. */
function scheduler_pull_request_watcher(?string $status, string $healthState = 'open', ?string $headSha = null): TaskPullRequestWatcher
{
    $watcher = new readonly class($status, $healthState, $headSha) implements TaskPullRequestWatcher
    {
        public function __construct(private ?string $state, private string $healthState, private ?string $headSha) {}

        public function status(Task $group): ?string
        {
            return $this->state;
        }

        public function health(Task $group): ?TaskPullRequestHealth
        {
            return new TaskPullRequestHealth(state: $this->healthState, baseRef: 'main', headSha: $this->headSha);
        }
    };
    app()->instance(TaskPullRequestWatcher::class, $watcher);

    return $watcher;
}

it('does not push a fixup commit to a pull request that already merged or closed and names the commit', function (string $state): void {
    $url = 'https://github.com/acme/orbit/pull/77';
    [$group, $task, , $publisher] = scheduler_approved_subtask('fixup-orphan-'.$state, last: true);
    $task->update(['position' => 2, 'fixup_problem' => 'check:Gateway']);
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Models', 'brief' => 'Store the records.', 'status' => TaskStatus::Completed]);
    $group->update(['pr_url' => $url, 'settled_at' => now()->subHour()]);
    scheduler_pull_request_watcher($state);

    app(TaskScheduler::class)->tick();

    $reason = TaskScheduler::OrphanedCommitPrefix.'Orbit did not push commit '.str_repeat('c', 40).' of subtask #'.$task->id.' because '.$url.' is already '.$state.'. Push that commit to a new branch and open a pull request, or cancel the group.';
    expect($publisher->pushes)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40));

    app(TaskScheduler::class)->tick();

    expect($publisher->pushes)->toBe([]);
})->with(['merged', 'closed']);

it('does not push a fixup commit while the pull request state is unreadable', function (): void {
    [$group, $task, , $publisher] = scheduler_approved_subtask('fixup-unknown', last: true);
    $task->update(['position' => 2, 'fixup_problem' => 'check:Gateway']);
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Models', 'brief' => 'Store the records.', 'status' => TaskStatus::Completed]);
    $group->update(['pr_url' => 'https://github.com/acme/orbit/pull/77', 'settled_at' => now()->subHour()]);
    scheduler_pull_request_watcher(null);

    app(TaskScheduler::class)->tick();

    expect($publisher->pushes)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->communication_failures)->toBe(1)
        ->and($group->fresh()?->assistance_requested)->toBeFalse();
});

it('asks for assistance at settle when the pull request merged before the fixup commit reached it', function (): void {
    $url = 'https://github.com/acme/orbit/pull/77';
    [$group, $task, , $publisher] = scheduler_approved_subtask('fixup-late-merge', last: true);
    $task->update(['position' => 2, 'fixup_problem' => 'check:Gateway']);
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Models', 'brief' => 'Store the records.', 'status' => TaskStatus::Completed]);
    $group->update(['pr_url' => $url, 'settled_at' => now()->subHour()]);
    scheduler_pull_request_watcher('open', 'merged', 'abc123');

    app(TaskScheduler::class)->tick();

    $reason = TaskScheduler::OrphanedCommitPrefix.'Commit '.str_repeat('c', 40).' reached task-'.$group->id.' after '.$url.' merged at abc123. Open a pull request for task-'.$group->id.', or complete the group.';
    expect($publisher->pushes)->toBe([$group->id])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe($reason);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling);
});

it('prepares a fixup review without pull request fields', function (): void {
    $project = scheduler_app('fixup-review');
    $instance = scheduler_instance($project, scheduler_node('fixup-review-node', '10.44.3.41'), 'workspace');
    $group = queued_group($project, 'Fixup review', $instance);
    scheduler_bind_claim($instance, new class implements AgentSpawner
    {
        public function spawnReviewer(Task $task): ?int
        {
            return test_agent_thread($task->parent, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->parent, 'implementer-thread', $task)->id;
        }

        public function requestReview(Task $task): void {}
    });

    app(TaskScheduler::class)->claimNext();
    test_pass_baseline();
    $group->update(['pr_url' => 'https://github.com/acme/orbit/pull/42']);
    app(TaskScheduler::class)->settleImplementer($group->tasks()->sole());

    expect(app(TaskTurnReceipts::class)->prepared)->toBe(['implementer', 'reviewer']);
});

it('keeps retrying a failed push after the fifth failure asks for assistance', function (): void {
    [$group, $task, $signer, $publisher] = scheduler_approved_subtask('push-assist');
    $publisher->pushFailures = 6;
    $sha = str_repeat('c', 40);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        if ($attempt > 0) {
            $this->travel(TaskScheduler::retryDelaySeconds($attempt))->seconds();
        }
        app(TaskScheduler::class)->tick();
    }

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_question)->toBeNull()
        ->and($task->fresh()?->assistance_reason)->toBe(TaskScheduler::PublicationFailedPrefix.'The task branch could not be pushed.')
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->fresh()?->assistance_question)->toBeNull()
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_reason)->toBe(TaskScheduler::PublicationFailedPrefix.'The task branch could not be pushed.')
        ->and($task->comments()->sole()->commit_sha)->toBe($sha)
        ->and($signer->messages)->toHaveCount(1)
        ->and($publisher->pushes)->toHaveCount(6)
        ->and(Task::query()->where('title', 'Routes')->sole()->status)->toBe(TaskStatus::Todo);

    $this->travel(TaskScheduler::retryDelaySeconds(6))->seconds();
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

it('does not replace an open direction request when publication fails', function (): void {
    [$group, $task, , $publisher] = scheduler_approved_subtask('push-direction');
    $publisher->pushFailures = 5;
    for ($attempt = 0; $attempt < 4; $attempt++) {
        if ($attempt > 0) {
            $this->travel(TaskScheduler::retryDelaySeconds($attempt))->seconds();
        }
        app(TaskScheduler::class)->tick();
    }
    $question = 'Which remote should receive the branch?';
    $reason = "The implementer is blocked: The remote rejected the push.\n\nQuestion: {$question}";
    $task->update([
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
        'communication_failures' => 4,
    ]);
    $group->update([
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);

    $this->travel(TaskScheduler::retryDelaySeconds(4))->seconds();
    app(TaskScheduler::class)->tick();

    expect($publisher->pushes)->toHaveCount(5)
        ->and($task->fresh()?->communication_failures)->toBe(5)
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe($question)
        ->and($task->fresh()?->assistance_reason)->toBe($reason)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_reason)->toBe($reason);
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
    $project = scheduler_app('started-app');
    $instance = scheduler_instance($project, scheduler_node('started-node', '10.44.0.96'), 'started');
    $group = queued_group($project, 'Started', $instance);
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
 * @return array{Task, Task, FakeTaskTurnReceipts, object, FakeTaskCheckRunner, object, object}
 */
function scheduler_review(array $receipts, bool $notified = true): array
{
    static $octet = 30;
    $octet++;
    $project = scheduler_app('review-ws-'.$octet);
    $node = scheduler_node('review-ws-node-'.$octet, '10.44.2.'.$octet);
    $instance = scheduler_instance($project, $node, 'workspace');
    $group = queued_group($project, 'Review workspace', $instance);
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

        public function headCommit(Instance $instance): ?string
        {
            return str_repeat('a', 40);
        }

        public function currentBranch(Instance $instance): ?string
        {
            return 'task-'.$this->groupId;
        }
    });
    app()->instance(AgentSpawner::class, new class implements AgentSpawner
    {
        public function spawnReviewer(Task $task): ?int
        {
            return test_agent_thread($task->parent, 'spawned-reviewer')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->parent, 'spawned-implementer-'.$task->id, $task)->id;
        }

        public function requestReview(Task $task): void {}
    });
    $receipts = new FakeTaskTurnReceipts($receipts);
    app()->instance(TaskTurnReceipts::class, $receipts);
    $checks = app(TaskCheckRunner::class);
    if (! $checks instanceof FakeTaskCheckRunner) {
        throw new RuntimeException('The review test needs the fake check runner.');
    }
    $dispatcher = new class implements AgentCommandDispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;

            return ['sequence' => count($this->commands), 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    $reader = new class($notified ? 'review-turn' : 'handoff-turn') implements AgentSnapshotReader
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
    app()->instance(AgentSnapshotReader::class, $reader);
    $signer = new class implements TaskWorkspaceSigner
    {
        /** @var list<string> */
        public array $messages = [];

        public function commit(Instance $instance, string $message): ?string
        {
            $this->messages[] = $message;

            return str_repeat('c', 40);
        }
    };
    app()->instance(TaskWorkspaceSigner::class, $signer);
    // ADR 0160: every approval pushes the task branch.
    app()->instance(TaskPullRequestPublisher::class, new class implements TaskPullRequestPublisher
    {
        public function publish(Task $group, string $body, string $commit): string
        {
            return 'https://github.com/acme/orbit/pull/1';
        }

        public function push(Task $group, string $commit): void {}
    });

    return [$group->fresh(['tasks', 'taskable']) ?? $group, $task->fresh() ?? $task, $receipts, $signer, $checks, $dispatcher, $reader];
}

it('resumes a reviewer topology request with ready or the failure reason without advancing review or asking for assistance', function (bool $fails): void {
    [$group, $task, , $signer, $checks, $dispatcher, $reader] = scheduler_review([
        FakeTaskTurnReceipts::contents('topology_requested', 'Discovery needs Nodes.'),
        FakeTaskTurnReceipts::contents('approved', 'Checked with available discovery.'),
    ]);
    $topology = new FakeTaskWorkspaceTopology;
    $topology->fails = $fails;
    app()->instance(TaskWorkspaceTopology::class, $topology);

    app(TaskScheduler::class)->tick();

    expect($topology->calls)->toBe([['acquire', $group->taskable_id, $group->id]])
        ->and($task->fresh()->status)->toBe(TaskStatus::Reviewing)
        ->and($group->fresh()->assistance_requested)->toBeFalse()
        ->and($signer->messages)->toBe([])
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and(json_encode($dispatcher->commands))->toContain($fails ? 'acquisition failed: The topology could not be acquired.' : 'is ready.')
        ->and(json_encode($dispatcher->commands))->toContain('reviewer-')
        ->and(TaskQuestion::query()->count())->toBe(0);

    $reader->turnId = 'review-after-topology-request';
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Completed)
        ->and($task->fresh()->assistance_requested)->toBeFalse();
})->with([false, true]);

it('reuses the recorded topology reply after receipt removal fails', function (): void {
    [$group, $task, , , , $dispatcher] = scheduler_review([]);
    $topology = new FakeTaskWorkspaceTopology;
    app()->instance(TaskWorkspaceTopology::class, $topology);
    $receipt = TaskTurnReceipt::parse(FakeTaskTurnReceipts::contents('topology_requested', 'Need Nodes.'))->withThread($group->reviewer_agent_thread_id);
    $receipts = mock(TaskTurnReceipts::class);
    $receipts->shouldReceive('hasLegacyTurn')->andReturn(false);
    $receipts->shouldReceive('read')->twice()->andReturn($receipt);
    $receipts->shouldReceive('clear')->once()->andThrow(new TaskTurnReceiptException('Receipt removal interrupted.'));
    $receipts->shouldReceive('clear')->once();
    $receipts->shouldReceive('prepare')->once();

    app(TaskScheduler::class)->tick();
    expect($dispatcher->commands)->toBe([]);
    app(TaskScheduler::class)->tick();

    expect($topology->calls)->toHaveCount(1)
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and(json_encode($dispatcher->commands))->toContain('is ready.')
        ->and($task->comments()->count())->toBe(1);
});

it('answers a second reviewer topology request with already held', function (): void {
    [$group, $task, , , , $dispatcher, $reader] = scheduler_review([
        FakeTaskTurnReceipts::contents('topology_requested', 'First request.'),
        FakeTaskTurnReceipts::contents('topology_requested', 'Second request.'),
    ]);
    $topology = new FakeTaskWorkspaceTopology;
    app()->instance(TaskWorkspaceTopology::class, $topology);
    app(TaskScheduler::class)->tick();
    // The previous stopped turn may still be visible while the resume is being delivered.
    app(TaskScheduler::class)->tick();
    expect($dispatcher->commands)->toHaveCount(1);
    $reader->turnId = 'second-request-turn';
    app(TaskScheduler::class)->tick();

    expect($topology->held)->toBe([$group->id])
        ->and($dispatcher->commands)->toHaveCount(2)
        ->and(json_encode($dispatcher->commands[1]))->toContain('is already held.')
        ->and($task->fresh()->status)->toBe(TaskStatus::Reviewing);
});

it('approves a review when the workspace is unchanged since the review request', function (): void {
    [$group, $task, , $signer, $checks, $dispatcher, $reader] = scheduler_review([
        FakeTaskTurnReceipts::contents('approved', 'Checked the models.'),
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
        FakeTaskTurnReceipts::contents('approved', 'Checked the models.'),
    ]);
    $checks->tree = str_repeat('c', 40);
    $reminder = 'Orbit could not confirm the review is complete. '.TaskScheduler::WorkspaceChangedReminder.' '.TaskTurnInstructions::reviewer(threadId: $group->reviewer_agent_thread_id);

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
        ->and($dispatcher->commands[0]['message']['text'])->toBe(TaskTurnFetchNotice::Failed."\n\n".$reminder);

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
        FakeTaskTurnReceipts::contents('approved', 'Checked the models.'),
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
        FakeTaskTurnReceipts::contents('approved', 'Checked the models.'),
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
        FakeTaskTurnReceipts::contents($outcome, 'Distinct findings that must not be applied.', $question),
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
        FakeTaskTurnReceipts::contents('approved', 'Checked the models.'),
        null,
        FakeTaskTurnReceipts::contents('ready_for_review', 'Operator follow-up.'),
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
        FakeTaskTurnReceipts::contents($outcome, 'Distinct findings that must not be applied.', $question),
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
        FakeTaskTurnReceipts::contents('approved', 'Checked the models.'),
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
        FakeTaskTurnReceipts::contents('approved', 'Checked the models.'),
    ]);
    $stored = str_repeat('d', 40);
    $checks->head = $stored;
    $checks->parent = str_repeat('a', 40);
    $checks->commitTree = str_repeat('b', 40);
    $signer = new class implements TaskWorkspaceSigner
    {
        public int $calls = 0;

        public function commit(Instance $instance, string $message): ?string
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

        public function publish(Task $group, string $body, string $commit): string
        {
            return 'https://github.com/acme/orbit/pull/1';
        }

        public function push(Task $group, string $commit): void
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

    $this->travel(TaskScheduler::retryDelaySeconds(1))->seconds();
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
        FakeTaskTurnReceipts::contents('approved', 'Checked the models.'),
    ]);
    $stored = str_repeat('d', 40);
    $signer = new class($stored) implements TaskWorkspaceSigner
    {
        public int $calls = 0;

        public function __construct(private string $sha) {}

        public function commit(Instance $instance, string $message): ?string
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

        public function publish(Task $group, string $body, string $commit): string
        {
            return 'https://github.com/acme/orbit/pull/1';
        }

        public function push(Task $group, string $commit): void
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
        FakeTaskTurnReceipts::contents('approved', 'Checked the models.'),
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
