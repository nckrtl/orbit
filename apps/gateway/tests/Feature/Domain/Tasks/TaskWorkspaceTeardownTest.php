<?php

declare(strict_types=1);

use App\Actions\Instances\RemoveInstanceAction;
use App\Actions\Processes\CascadeInstanceProcessesAction;
use App\Actions\Schedules\CascadeInstanceSchedulesAction;
use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\InstanceSourceRevalidationExpectation;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Instances\Removal\ProductionInstanceContentRetention;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use Tests\Support\LifecycleSshExecutor;

beforeEach(function (): void {
    bind_task_node_reachability();
});

it('runs project teardown and removes only the route-free workspace when a group is cancelled', function (): void {
    app(TaskExtensionState::class)->enable();
    $world = task_teardown_world('cancel');
    $cancelled = app(CancelTaskGroupAction::class)->execute($world['group']);

    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->taskable_id)->toBeNull()
        ->and($cancelled->assistance_requested)->toBeFalse()
        ->and(Instance::query()->find($world['workspace']->id))->toBeNull()
        ->and(Instance::query()->find($world['neighbor']->id))->not->toBeNull();
    task_teardown_expect_commands($world['harness'], [$world['workspace']->checkout_path]);
    task_teardown_expect_route_free($world['workspace']->id);
});

it('runs project teardown and removes only the route-free workspace when a group is completed', function (): void {
    app(TaskExtensionState::class)->enable();
    $world = task_teardown_world('complete', TaskGroupStatus::Settling);
    $completed = app(CompleteTaskGroupAction::class)->execute($world['group']);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBeNull()
        ->and($completed->assistance_requested)->toBeFalse()
        ->and(Instance::query()->find($world['workspace']->id))->toBeNull()
        ->and(Instance::query()->find($world['neighbor']->id))->not->toBeNull();
    task_teardown_expect_commands($world['harness'], [$world['workspace']->checkout_path]);
    task_teardown_expect_route_free($world['workspace']->id);
});

it('runs project teardown for an unattached route-free workspace when the group is cancelled', function (): void {
    app(TaskExtensionState::class)->enable();
    $world = task_teardown_world('unattached-cancel', TaskGroupStatus::Todo, attached: false);
    $cancelled = app(CancelTaskGroupAction::class)->execute($world['group']);

    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->taskable_id)->toBeNull()
        ->and(Instance::query()->find($world['workspace']->id))->toBeNull()
        ->and(Instance::query()->find($world['neighbor']->id))->not->toBeNull();
    task_teardown_expect_commands($world['harness'], [$world['workspace']->checkout_path]);
    task_teardown_expect_route_free($world['workspace']->id);
});

it('sweeps attached and unattached route-free workspaces through project teardown and leaves other workspaces', function (): void {
    app(TaskExtensionState::class)->enable();
    $ended = task_teardown_world('sweep-attached', TaskGroupStatus::Cancelled);
    $unattached = task_teardown_workspace($ended['project'], $ended['node'], 'sweep-loose', TaskGroupStatus::Cancelled, attached: false);
    $running = task_teardown_workspace($ended['project'], $ended['node'], 'sweep-running', TaskGroupStatus::Running);

    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(2)
        ->and(Instance::query()->find($ended['workspace']->id))->toBeNull()
        ->and(Instance::query()->find($unattached['workspace']->id))->toBeNull()
        ->and(Instance::query()->find($running['workspace']->id))->not->toBeNull()
        ->and(Instance::query()->find($ended['neighbor']->id))->not->toBeNull()
        ->and($ended['group']->fresh()?->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($unattached['group']->fresh()?->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($running['group']->fresh()?->status)->toBe(TaskGroupStatus::Running);
    task_teardown_expect_commands($ended['harness'], [
        $ended['workspace']->checkout_path,
        $unattached['workspace']->checkout_path,
    ]);
    task_teardown_expect_route_free($ended['workspace']->id);
    task_teardown_expect_route_free($unattached['workspace']->id);
});

it('keeps the source and record when teardown fails and a retry removes only the owned workspace', function (): void {
    app(TaskExtensionState::class)->enable();
    $world = task_teardown_world('complete-retry', TaskGroupStatus::Settling);
    $world['harness']->exit = 1;

    $completed = app(CompleteTaskGroupAction::class)->execute($world['group']);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBe($world['workspace']->id)
        ->and($completed->assistance_requested)->toBeFalse()
        ->and($completed->assistance_reason)->toBe('Workspace removal failed: Teardown step failed.')
        ->and($world['workspace']->fresh()?->status)->toBe(InstanceState::SourceResolved)
        ->and($world['harness']->source->finalized)->toBe([])
        ->and(InstanceRemoval::query()->count())->toBe(0)
        ->and(Instance::query()->find($world['neighbor']->id))->not->toBeNull();

    $world['harness']->exit = 0;
    $retried = app(CompleteTaskGroupAction::class)->execute($completed);

    expect($retried->status)->toBe(TaskGroupStatus::Completed)
        ->and($retried->assistance_requested)->toBeFalse()
        ->and($retried->assistance_reason)->toBeNull()
        ->and($retried->taskable_id)->toBeNull()
        ->and(Instance::query()->find($world['workspace']->id))->toBeNull()
        ->and(Instance::query()->find($world['neighbor']->id))->not->toBeNull()
        ->and($world['harness']->source->finalized)->toBe([$world['workspace']->id]);
    task_teardown_expect_commands($world['harness'], [
        $world['workspace']->checkout_path,
        $world['workspace']->checkout_path,
    ]);
});

it('keeps a swept workspace when teardown fails and retries only that workspace after the backoff', function (): void {
    $this->freezeTime();
    app(TaskExtensionState::class)->enable();
    $world = task_teardown_world('sweep-retry', TaskGroupStatus::Cancelled);
    $world['harness']->exit = 1;

    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0)
        ->and($world['group']->fresh()?->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($world['group']->fresh()?->assistance_requested)->toBeFalse()
        ->and($world['group']->fresh()?->assistance_reason)->toBe('Workspace removal failed: Teardown step failed.')
        ->and($world['workspace']->fresh()?->status)->toBe(InstanceState::SourceResolved)
        ->and($world['harness']->source->finalized)->toBe([])
        ->and(InstanceRemoval::query()->count())->toBe(0);

    // Cross a real second boundary to prove the backoff clock stays frozen.
    time_sleep_until(floor(microtime(true)) + 1);
    $this->travel(59)->seconds();
    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0)
        ->and(Instance::query()->find($world['workspace']->id))->not->toBeNull()
        ->and($world['harness']->transport->inputs)->toHaveCount(1);

    $this->travel(2)->seconds();
    $world['harness']->exit = 0;

    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1)
        ->and($world['group']->fresh()?->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($world['group']->fresh()?->assistance_requested)->toBeFalse()
        ->and($world['group']->fresh()?->assistance_reason)->toBeNull()
        ->and(Instance::query()->find($world['workspace']->id))->toBeNull()
        ->and(Instance::query()->find($world['neighbor']->id))->not->toBeNull()
        ->and($world['harness']->source->finalized)->toBe([$world['workspace']->id]);
});

it('retains the workspace when the teardown outcome is unconfirmed', function (): void {
    app(TaskExtensionState::class)->enable();
    $world = task_teardown_world('unconfirmed');
    $world['harness']->exit = 255;

    expect(fn () => app(CancelTaskGroupAction::class)->execute($world['group']))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.teardown_step_failed')
                ->and($exception->details)->toBe(['step' => 'project-cleanup', 'outcome' => 'unconfirmed']);
        });

    $fresh = $world['group']->fresh();
    expect($fresh?->status)->toBe(TaskGroupStatus::Running)
        ->and($fresh?->assistance_requested)->toBeTrue()
        ->and($fresh?->assistance_reason)->toBe('Workspace removal failed: Teardown step failed.')
        ->and($fresh?->taskable_id)->toBe($world['workspace']->id)
        ->and($world['workspace']->fresh()?->status)->toBe(InstanceState::SourceResolved)
        ->and($world['harness']->source->finalized)->toBe([])
        ->and(InstanceRemoval::query()->count())->toBe(0)
        ->and(Instance::query()->find($world['neighbor']->id))->not->toBeNull();
});

it('refuses speculative deletion and skips teardown when the source cannot be inspected', function (): void {
    app(TaskExtensionState::class)->enable();
    $world = task_teardown_world('source-missing', TaskGroupStatus::Cancelled, attached: false);
    $world['harness']->source->unavailable = true;

    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0);

    $fresh = $world['group']->fresh();
    expect($fresh?->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($fresh?->assistance_requested)->toBeFalse()
        ->and($fresh?->assistance_reason)->toBe('Workspace removal failed: The checkout is missing.')
        ->and($world['harness']->transport->inputs)->toBe([])
        ->and($world['harness']->source->finalized)->toBe([])
        ->and(InstanceRemoval::query()->count())->toBe(0)
        ->and($world['workspace']->fresh()?->status)->toBe(InstanceState::SourceResolved)
        ->and(Instance::query()->find($world['neighbor']->id))->not->toBeNull();
});

/**
 * @return array{project: Project, node: Node, group: Task, workspace: Instance, neighbor: Instance, harness: TaskTeardownHarness}
 */
function task_teardown_world(string $suffix, TaskGroupStatus $status = TaskGroupStatus::Running, bool $attached = true): array
{
    $project = Project::query()->create([
        'name' => 'Teardown '.$suffix,
        'slug' => 'teardown-'.$suffix,
        'type' => 'laravel-app',
        'repository_url' => "https://example.test/teardown-{$suffix}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'teardown-'.$suffix,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.(20 + (abs(crc32($suffix)) % 200)),
        'wireguard_ip' => '10.44.1.'.(20 + (abs(crc32($suffix)) % 200)),
    ]);
    $node->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    ProjectLifecycleStep::query()->create([
        'project_id' => $project->id,
        'phase' => 'teardown',
        'name' => 'project-cleanup',
        'command' => 'project-cleanup',
        'timeout_seconds' => 30,
        'position' => 0,
    ]);
    $harness = task_teardown_harness();
    $placed = task_teardown_workspace($project, $node, $suffix, $status, $attached);

    return [
        'project' => $project,
        'node' => $node,
        'group' => $placed['group'],
        'workspace' => $placed['workspace'],
        'neighbor' => $placed['neighbor'],
        'harness' => $harness,
    ];
}

/**
 * @return array{group: Task, workspace: Instance, neighbor: Instance}
 */
function task_teardown_workspace(Project $project, Node $node, string $suffix, TaskGroupStatus $status, bool $attached = true): array
{
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Teardown '.$suffix,
        'brief' => 'Remove the workspace through project teardown.',
        'status' => $status,
    ]);
    $name = $attached ? 'workspace-'.$suffix : 'task-'.$group->id;
    $workspace = task_teardown_instance($project, $node, $name, $attached ? null : $name);
    $neighbor = task_teardown_instance($project, $node, 'neighbor-'.$suffix, null);

    if ($attached) {
        $group->taskable()->associate($workspace);
        $group->save();
    }

    return [
        'group' => $group->fresh(['project', 'taskable']) ?? $group,
        'workspace' => $workspace,
        'neighbor' => $neighbor,
    ];
}

function task_teardown_instance(Project $project, Node $node, string $name, ?string $branchOverride): Instance
{
    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'source_layout' => 'checkout',
        'checkout_path' => "/srv/orbit/apps/{$project->slug}/{$name}",
        'branch' => $name,
        'branch_override' => $branchOverride,
        'starting_commit' => str_repeat('a', 40),
        'task_workspace_routed' => false,
        'status' => InstanceState::SourceResolved,
    ]);
}

function task_teardown_harness(): TaskTeardownHarness
{
    $harness = new TaskTeardownHarness;
    $harness->transport = new LifecycleSshExecutor(result: fn (): int => $harness->exit);
    $harness->source = new TaskTeardownSource;
    $harness->routes = new TaskTeardownRoutes;
    app()->instance(InstanceRemover::class, new RemoveInstanceAction(
        $harness->source,
        $harness->source,
        $harness->routes,
        new ManagedCheckoutOverlap,
        new TaskTeardownEnvironmentLock,
        new TaskTeardownProcessLock,
        app(CascadeInstanceProcessesAction::class),
        new TaskTeardownSourceLock,
        app(ProductionInstanceContentRetention::class),
        app(RouteStateResolver::class),
        app(CascadeInstanceSchedulesAction::class),
        lifecycle: $harness->transport->runner(),
    ));

    return $harness;
}

/** @param list<string> $checkouts */
function task_teardown_expect_commands(TaskTeardownHarness $harness, array $checkouts): void
{
    $seen = array_column($harness->transport->inputs, 'checkout');
    $expected = $checkouts;
    sort($seen);
    sort($expected);

    expect($seen)->toBe($expected)
        ->and(array_values(array_unique(array_column($harness->transport->inputs, 'command'))))->toBe(['project-cleanup'])
        ->and(implode("\n", $harness->transport->shells))->not->toContain('e2e-bridge')
        ->and(array_values(array_unique($harness->source->forces)))->toBe([true])
        ->and($harness->routes->cleared)->toBe([]);
}

function task_teardown_expect_route_free(int $instanceId): void
{
    $member = InstanceRemovalMember::query()->where('instance_id', $instanceId)->sole();

    expect($member->route_id)->toBeNull()
        ->and($member->route_outcome)->toBe('none');
}

final class TaskTeardownHarness
{
    public int $exit = 0;

    public LifecycleSshExecutor $transport;

    public TaskTeardownSource $source;

    public TaskTeardownRoutes $routes;
}

final class TaskTeardownSource implements DevelopmentInstanceSourceFinalizer, DevelopmentInstanceSourceRemoval
{
    public bool $unavailable = false;

    /** @var list<bool> */
    public array $forces = [];

    /** @var list<int> */
    public array $finalized = [];

    public function inspect(Instance $instance, bool $force, bool $inspectContent = true): InstanceSourceInventory
    {
        $this->forces[] = $force;

        if ($this->unavailable) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-removal-inspect',
                errorCode: 'instance.source_path_mismatch',
                message: 'The checkout is missing.',
            );
        }

        $instance->loadMissing('project');
        $commit = is_string($instance->starting_commit) && $instance->starting_commit !== ''
            ? $instance->starting_commit
            : str_repeat('a', 40);
        $paths = [$instance->checkout_path];
        $identity = $instance->project->repository_identity;

        return new InstanceSourceInventory(
            instanceId: $instance->id,
            layout: $instance->source_layout,
            repositoryIdentity: $identity,
            checkoutPath: $instance->checkout_path,
            root: '/srv/orbit/apps',
            branch: $instance->branch,
            startingCommit: $commit,
            commonRepositoryPath: $instance->checkout_path,
            sourceIdentity: 'task-workspace:'.$instance->id,
            linkedWorktreePaths: $paths,
            digest: hash('sha256', $instance->id.'|'.$instance->checkout_path.'|'.$commit.'|'.$identity),
        );
    }

    public function remove(Instance $instance, InstanceSourceInventory $inventory, bool $force): void
    {
        throw new LogicException('Task cleanup uses the durable source finalizer.');
    }

    public function prepare(InstanceRemovalMember $member, ?InstanceSourceRevalidationExpectation $expectation = null): void {}

    public function revalidate(InstanceRemovalMember $member, ?InstanceSourceRevalidationExpectation $expectation = null): InstanceSourceRevalidationState
    {
        return InstanceSourceRevalidationState::Present;
    }

    public function inspectRecorded(
        InstanceRemovalMember $member,
        InstanceSourceRevalidationState $state,
        ?InstanceSourceRevalidationExpectation $expectation = null,
    ): InstanceSourceInventory {
        throw new LogicException('Task cleanup uses the durable source finalizer.');
    }

    public function finalize(InstanceRemovalMember $member, ?InstanceSourceRevalidationExpectation $expectation = null): string
    {
        $this->finalized[] = $member->instance_id;

        return hash('sha256', 'receipt:'.$member->instance_id);
    }
}

final class TaskTeardownRoutes implements InstanceRemovalProjector
{
    /** @var list<int> */
    public array $cleared = [];

    public function clearRouteTarget(InstanceRemovalMember $member): string
    {
        $this->cleared[] = $member->instance_id;

        return 'deleted';
    }

    public function withdrawPhpPool(InstanceRemovalMember $member): void {}

    public function cleanupRuntime(InstanceRemovalMember $member): void {}
}

final class TaskTeardownEnvironmentLock implements InstanceEnvironmentOperationLock
{
    public function run(array $instanceIds, Closure $operation): mixed
    {
        return $operation();
    }
}

final class TaskTeardownProcessLock implements ProcessAdmissionLock
{
    public function run(array $instanceIds, Closure $operation): mixed
    {
        return $operation();
    }
}

final class TaskTeardownSourceLock implements AppDevSourceOperationLock
{
    public function synchronized(int $nodeId, Closure $operation): mixed
    {
        return $operation();
    }
}
