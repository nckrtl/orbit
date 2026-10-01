<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Data\Tasks\TaskGroupData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Instances\DevelopmentInstanceCheckoutCopier;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\InstanceCreation;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCeilings;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectNodeExclusion;
use App\Models\Task;
use Tests\Support\FakeDevelopmentInstanceCheckoutCopier;

function provisioner_app(
    string $slug,
    ?string $root = 'public',
    ProjectType $type = ProjectType::LaravelApp,
): Project {
    return Project::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
        'type' => $type,
        'default_branch' => 'main',
        'root' => $root,
    ]);
}

function provisioner_node(string $name, string $ip): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => "{$name}.test",
        'public_ssh_host' => $ip,
        'wireguard_ip' => $ip,
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $node->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);

    $node->processes()->create([
        'name' => 't3-code',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/home/orbit',
        'runtime_config' => ['command' => ['/home/orbit/.local/bin/t3', 'serve', "--host={$ip}", '--port=3773', '--no-browser']],
        'restart_policy' => 'always',
        'keep_alive' => true,
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function provisioner_group(Project $project, string $title = 'Workspace'): Task
{
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => $title,
        'brief' => "{$title} brief",
        'status' => TaskGroupStatus::Reserved,
    ]);
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'First',
        'brief' => 'First subtask',
        'status' => TaskStatus::Todo,
    ]);

    return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
}

function bind_task_workspace_fakes(): object
{
    $accounts = new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    };
    $destination = new class implements InstanceDestinationGuard
    {
        public function assertUnoccupied(Node $node, StoragePath $destination): void {}
    };
    $source = new class implements DevelopmentInstanceSourceLifecycle
    {
        /** @var list<string> */
        public array $calls = [];

        public int $failInspectPreparedTimes = 0;

        public int $failInspectResolvedTimes = 0;

        public function prepare(Instance $instance, bool $allowExisting): void
        {
            $this->calls[] = 'prepare';
        }

        public function inspectPrepared(Instance $instance): void
        {
            $this->calls[] = 'inspect-prepared';

            if ($this->failInspectPreparedTimes > 0) {
                $this->failInspectPreparedTimes--;

                throw new RuntimeConvergenceException(
                    step: 'app-instance-source-inspect',
                    errorCode: 'instance.source_identity_invalid',
                    message: 'The checkout is missing.',
                );
            }
        }

        public function resolve(Instance $instance): DevelopmentSourceResolution
        {
            $this->calls[] = 'resolve';

            return new DevelopmentSourceResolution($instance->name, str_repeat('a', 40));
        }

        public function inspectResolved(Instance $instance): DevelopmentSourceResolution
        {
            $this->calls[] = 'inspect-resolved';

            if ($this->failInspectResolvedTimes > 0) {
                $this->failInspectResolvedTimes--;

                throw new RuntimeConvergenceException(
                    step: 'app-instance-source-inspect-resolved',
                    errorCode: 'instance.source_identity_invalid',
                    message: 'The checkout is missing.',
                );
            }

            return new DevelopmentSourceResolution((string) $instance->branch, (string) $instance->starting_commit);
        }
    };
    $development = new class implements DevelopmentInstanceProvisioner
    {
        public int $reserves = 0;

        public int $completes = 0;

        public function reserve(Instance $instance, ?string $domain): void
        {
            $this->reserves++;
        }

        public function complete(
            Instance $instance,
            ?string $domain,
            bool $setupPending = false,
        ): Instance {
            $this->completes++;

            return $instance->refresh();
        }
    };

    $copies = new FakeDevelopmentInstanceCheckoutCopier;

    app()->instance(ManagedUserAccountResolver::class, $accounts);
    app()->instance(InstanceDestinationGuard::class, $destination);
    app()->instance(DevelopmentInstanceSourceLifecycle::class, $source);
    app()->instance(DevelopmentInstanceProvisioner::class, $development);
    app()->instance(DevelopmentInstanceCheckoutCopier::class, $copies);

    return (object) ['source' => $source, 'development' => $development, 'copies' => $copies];
}

function provisioner_default(Project $project, Node $node, InstanceState $status = InstanceState::Active): Instance
{
    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/orbit/apps/'.$project->slug.'/default',
        'branch' => 'main',
        'branch_override' => 'main',
        'starting_commit' => str_repeat('b', 40),
        'root' => 'public',
        'status' => $status,
    ]);
}

it('leaves a group reserved when no app-dev Node can take the workspace', function (): void {
    $project = provisioner_app('lonely');
    $group = provisioner_group($project);
    bind_task_workspace_fakes();

    expect(app(InstanceProvisioning::class)->provision(new InstanceProvisionIntent($group, true)))
        ->toBeNull();
});

it('creates a non-visitable Orbit checkout without activating a Route', function (): void {
    $project = provisioner_app('orbit');
    $node = provisioner_node('orbit-dev', '10.44.0.101');
    $group = provisioner_group($project, 'Isolated');
    $fakes = bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance)->toBeInstanceOf(Instance::class)
        ->and($instance?->node_id)->toBe($node->id)
        ->and($instance?->name)->toBe(TaskWorkspaceName::for($group))
        ->and($instance?->checkout_path)->toBe('/srv/orbit/apps/orbit/'.TaskWorkspaceName::for($group))
        ->and($instance?->branch_override)->toBe(TaskWorkspaceName::for($group))
        ->and($instance?->branch)->toBe(TaskWorkspaceName::for($group))
        ->and($instance?->status)->toBe(InstanceState::SourceResolved)
        ->and($instance?->task_workspace_routed)->toBeFalse()
        ->and($instance?->routes()->count())->toBe(0)
        ->and($fakes->source->calls)->toBe(['prepare', 'inspect-prepared', 'resolve', 'inspect-prepared', 'inspect-resolved'])
        ->and($fakes->development->reserves)->toBe(0)
        ->and($fakes->development->completes)->toBe(0);
});

it('activates a visitable workspace through the development provisioner', function (): void {
    $project = provisioner_app('shop');
    $node = provisioner_node('shop-dev', '10.44.0.102');
    $group = provisioner_group($project, 'Inspect');
    $fakes = bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

    expect($instance?->node_id)->toBe($node->id)
        ->and($instance?->root)->toBe('public')
        ->and($instance?->status)->toBe(InstanceState::SourceResolved)
        ->and($instance?->task_workspace_routed)->toBeTrue()
        ->and($fakes->development->reserves)->toBe(1)
        ->and($fakes->development->completes)->toBe(1);
});

it('creates a visitable Task workspace at the repository root for each package type', function (ProjectType $type): void {
    $project = provisioner_app($type->value, '.', $type);
    $node = provisioner_node($type->value.'-dev', '10.44.0.125');
    $group = provisioner_group($project);
    $fakes = bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

    expect($instance)->toBeInstanceOf(Instance::class)
        ->and($instance?->node_id)->toBe($node->id)
        ->and($instance?->root)->toBe('.')
        ->and($instance?->status)->toBe(InstanceState::SourceResolved)
        ->and($fakes->development->reserves)->toBe(1)
        ->and($fakes->development->completes)->toBe(1);
})->with([
    'laravel-package' => ProjectType::LaravelPackage,
    'node-package' => ProjectType::NodePackage,
]);

it('reuses an already assigned Task workspace', function (): void {
    $project = provisioner_app('reuse');
    $node = provisioner_node('reuse-dev', '10.44.0.103');
    $group = provisioner_group($project);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'existing',
        'checkout_path' => '/srv/orbit/apps/reuse/existing',
        'status' => InstanceState::SourceResolved,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    bind_task_workspace_fakes();

    expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group->fresh(['taskable']) ?? $group, true))?->id)
        ->toBe($instance->id);
});

it('returns null when a visitable App lacks a web root', function (): void {
    $project = provisioner_app('bare', null);
    provisioner_node('bare-dev', '10.44.0.104');
    $group = provisioner_group($project);
    bind_task_workspace_fakes();

    expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true)))
        ->toBeNull();
});

it('returns null when destination occupation refuses the checkout', function (): void {
    $project = provisioner_app('blocked');
    provisioner_node('blocked-dev', '10.44.0.105');
    $group = provisioner_group($project);
    bind_task_workspace_fakes();
    app()->instance(InstanceDestinationGuard::class, new class implements InstanceDestinationGuard
    {
        public function assertUnoccupied(Node $node, StoragePath $destination): void
        {
            throw new ResourceOperationException(
                'instance.migration_conflict',
                'Instance destination is occupied by unmanaged data.',
                409,
            );
        }
    });

    expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false)))
        ->toBeNull();
});

it('skips an excluded app-dev Node before choosing the least loaded node', function (): void {
    $project = provisioner_app('orbit');
    $excluded = provisioner_node('sabre', '10.44.0.120');
    $allowed = provisioner_node('shark', '10.44.0.121');
    ProjectNodeExclusion::query()->create(['project_id' => $project->id, 'node_id' => $excluded->id]);
    $group = provisioner_group($project);
    bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance?->node_id)->toBe($allowed->id);
});

it('skips a non-T3 app-dev Node even when it has the lower id', function (): void {
    $project = provisioner_app('placement');
    $incapable = provisioner_node('no-t3', '10.44.0.110');
    $incapable->processes()->delete();
    $capable = provisioner_node('with-t3', '10.44.0.111');
    $group = provisioner_group($project);
    bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance?->node_id)->toBe($capable->id);
    $this->assertDatabaseMissing('instances', ['node_id' => $incapable->id]);
});

it('reports a capacity wait without creating a workspace when every capable Node is full', function (bool $otherNodeHasRoom): void {
    $project = provisioner_app('full');
    $incapable = provisioner_node('no-t3', '10.44.0.110');
    $incapable->processes()->delete();
    $capable = provisioner_node('full-t3', '10.44.0.111');
    foreach ($otherNodeHasRoom ? [$capable] : [$capable, $incapable] as $node) {
        $occupied = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => "occupied-{$node->id}",
            'checkout_path' => "/srv/orbit/apps/full/occupied-{$node->id}",
            'status' => InstanceState::SourceResolved,
        ]);
        for ($i = 0; $i < TaskCeilings::PerNode; $i++) {
            $active = provisioner_group($project, "Active {$node->id} {$i}");
            $active->taskable()->associate($occupied);
            $active->save();
        }
    }
    $group = provisioner_group($project);
    $fakes = bind_task_workspace_fakes();

    expect(fn () => app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false)))
        ->toThrow(fn (TaskCapacityException $exception) => expect($exception->fleetFull)->toBe(! $otherNodeHasRoom))
        ->and($fakes->source->calls)->toBe([]);
    $this->assertDatabaseCount('instances', $otherNodeHasRoom ? 1 : 2);
})->with([
    'another app-dev Node has room' => [true],
    'the whole fleet is full' => [false],
]);

it('places a group only on a Node that allows both its implementer and reviewer drivers', function (): void {
    $project = provisioner_app('mixed');
    $t3Only = provisioner_node('t3-only', '10.44.0.113');
    $both = provisioner_node('t3-and-pi', '10.44.0.114');
    $both->processes()->create([
        'name' => 'pi-server',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/home/orbit',
        'runtime_config' => ['command' => ['/home/orbit/.local/bin/pi-server']],
        'restart_policy' => 'always',
        'keep_alive' => true,
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
    $group = provisioner_group($project);
    $group->update(['implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 't3']);
    bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group->fresh() ?? $group, false));

    expect($instance?->node_id)->toBe($both->id);
    $this->assertDatabaseMissing('instances', ['node_id' => $t3Only->id]);
});

it('returns null when no Node allows the implementer driver', function (): void {
    $project = provisioner_app('no-pi');
    provisioner_node('t3-only', '10.44.0.115');
    $group = provisioner_group($project);
    $group->update(['implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 't3']);
    $fakes = bind_task_workspace_fakes();

    expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group->fresh() ?? $group, false)))->toBeNull()
        ->and($fakes->source->calls)->toBe([]);
});

it('returns null when the app-dev Node has no usable T3 process', function (string $reason): void {
    $project = provisioner_app('unavailable');
    $node = provisioner_node('unavailable', '10.44.0.112');
    match ($reason) {
        'missing' => $node->processes()->delete(),
        'unrelated' => $node->processes()->update(['name' => 'other-service']),
        'failed' => $node->processes()->update(['status' => LifecycleStatus::Failed]),
        'stopped' => $node->processes()->update(['desired_state' => DesiredProcessState::Stopped]),
        'no-address' => $node->update(['wireguard_ip' => null]),
        'empty-address' => $node->update(['wireguard_ip' => '']),
    };
    $group = provisioner_group($project);
    $fakes = bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance)->toBeNull()
        ->and($fakes->source->calls)->toBe([]);
    $this->assertDatabaseCount('instances', 0);
})->with(['missing', 'unrelated', 'failed', 'stopped', 'no-address', 'empty-address']);

describe('a workspace an interrupted claim left unattached', function (): void {
    it('resumes it on its own Node instead of the least loaded one', function (): void {
        $project = provisioner_app('orbit');
        provisioner_node('first', '10.44.0.130');
        $second = provisioner_node('second', '10.44.0.131');
        $group = provisioner_group($project);
        $left = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $second->id,
            'name' => TaskWorkspaceName::for($group),
            'checkout_path' => '/srv/orbit/apps/orbit/'.TaskWorkspaceName::for($group),
            'branch_override' => TaskWorkspaceName::for($group),
            'status' => InstanceState::CheckoutPrepared,
        ]);
        bind_task_workspace_fakes();

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

        expect($instance?->id)->toBe($left->id)
            ->and($instance?->status)->toBe(InstanceState::SourceResolved);
        $this->assertDatabaseCount('instances', 1);
    });

    it('waits for capacity on its own Node', function (): void {
        $project = provisioner_app('orbit');
        provisioner_node('roomy', '10.44.0.132');
        $full = provisioner_node('full', '10.44.0.133');
        $occupied = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $full->id,
            'name' => 'occupied',
            'checkout_path' => '/srv/orbit/apps/orbit/occupied',
            'status' => InstanceState::SourceResolved,
        ]);
        for ($i = 0; $i < TaskCeilings::PerNode; $i++) {
            $active = provisioner_group($project, "Active {$i}");
            $active->taskable()->associate($occupied);
            $active->save();
        }
        $group = provisioner_group($project);
        Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $full->id,
            'name' => TaskWorkspaceName::for($group),
            'checkout_path' => '/srv/orbit/apps/orbit/'.TaskWorkspaceName::for($group),
            'branch_override' => TaskWorkspaceName::for($group),
            'status' => InstanceState::Reserved,
        ]);
        bind_task_workspace_fakes();

        expect(fn () => app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false)))
            ->toThrow(fn (TaskCapacityException $exception) => expect($exception->fleetFull)->toBeFalse());
    });

    it('never adopts an Instance that only shares the workspace name', function (): void {
        $project = provisioner_app('orbit');
        $node = provisioner_node('only', '10.44.0.134');
        $group = provisioner_group($project);
        $lookalike = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => TaskWorkspaceName::for($group),
            'checkout_path' => '/srv/orbit/apps/orbit/'.TaskWorkspaceName::for($group),
            'branch_override' => 'feature-x',
            'status' => InstanceState::SourceResolved,
        ]);
        bind_task_workspace_fakes();

        expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false)))->toBeNull()
            ->and($lookalike->fresh()?->branch_override)->toBe('feature-x')
            ->and($lookalike->fresh()?->status)->toBe(InstanceState::SourceResolved);
        $this->assertDatabaseCount('instances', 1);
    });
});

describe('a task workspace copied from the default Instance', function (): void {
    it('copies the selected Node default and sits on the fetched default branch tip', function (bool $visitable): void {
        $project = provisioner_app($visitable ? 'shop' : 'orbit');
        $node = provisioner_node('copy-dev', '10.44.0.210');
        $source = provisioner_default($project, $node);
        $group = provisioner_group($project, 'Copied');
        $fakes = bind_task_workspace_fakes();

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, $visitable));
        $shown = TaskGroupData::fromModel($group->fresh() ?? $group)->toArray();

        expect($instance)->toBeInstanceOf(Instance::class)
            ->and($instance?->node_id)->toBe($node->id)
            ->and($instance?->name)->toBe(TaskWorkspaceName::for($group))
            ->and($instance?->branch)->toBe(TaskWorkspaceName::for($group))
            ->and($instance?->branch_override)->toBe(TaskWorkspaceName::for($group))
            ->and($instance?->starting_commit)->toBe($fakes->copies->fetchedTip)
            ->and($instance?->starting_commit)->not->toBe($fakes->copies->head)
            ->and($instance?->creation)->toBe(InstanceCreation::Copy)
            ->and($instance?->copy_mode)->toBe('reflink')
            ->and($instance?->source_instance_id)->toBe($source->id)
            ->and($instance?->status)->toBe(InstanceState::SourceResolved)
            ->and($instance?->routes()->count())->toBe(0)
            ->and($fakes->copies->fetchedCopies)->toBe(1)
            ->and($fakes->copies->branch)->toBe(TaskWorkspaceName::for($group))
            ->and($fakes->copies->defaultBranch)->toBe('main')
            ->and($fakes->copies->expectedHead)->toBe($fakes->copies->head)
            ->and($fakes->source->calls)->toBe(['inspect-prepared', 'inspect-resolved', 'inspect-prepared', 'inspect-resolved'])
            ->and($fakes->development->reserves)->toBe($visitable ? 1 : 0)
            ->and($fakes->development->completes)->toBe($visitable ? 1 : 0)
            ->and($source->fresh()?->status)->toBe(InstanceState::Active)
            ->and($source->fresh()?->branch)->toBe('main')
            ->and($shown['workspace_creation'])->toBe(InstanceCreation::Copy)
            ->and($shown['workspace_copy_mode'])->toBe('reflink')
            ->and($shown['workspace_fallback_reason'])->toBeNull();
    })->with([
        'orbit stays source resolved' => false,
        'a visitable Project still gets its Route' => true,
    ]);

    it('records a plain copy when reflink is unavailable', function (): void {
        $project = provisioner_app('shop');
        $node = provisioner_node('full-dev', '10.44.0.211');
        provisioner_default($project, $node);
        $group = provisioner_group($project);
        $fakes = bind_task_workspace_fakes();
        $fakes->copies->mode = 'full';

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

        expect($instance?->copy_mode)->toBe('full')
            ->and($group->fresh()?->workspace_copy_mode)->toBe('full')
            ->and($group->fresh()?->workspace_creation)->toBe(InstanceCreation::Copy);
    });

    it('keeps the selected Node when only another Node has an eligible default', function (): void {
        $project = provisioner_app('shop');
        $selected = provisioner_node('selected', '10.44.0.212');
        $sourceNode = provisioner_node('source', '10.44.0.213');
        provisioner_default($project, $sourceNode);
        $group = provisioner_group($project);
        $fakes = bind_task_workspace_fakes();

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

        expect($instance?->node_id)->toBe($selected->id)
            ->and($instance?->creation)->toBe(InstanceCreation::Repository)
            ->and($fakes->copies->fetchedCopies)->toBe(0)
            ->and($fakes->copies->inspections)->toBe(0)
            ->and($group->fresh()?->workspace_creation)->toBe(InstanceCreation::Repository)
            ->and($group->fresh()?->workspace_copy_mode)->toBeNull()
            ->and($group->fresh()?->workspace_fallback_reason)->toBe(TaskWorkspaceProvisioner::SourceUnavailableReason);
    });

    it('falls back to a fresh clone when the default Instance is missing or ineligible', function (string $cause): void {
        $project = provisioner_app('shop');
        $node = provisioner_node('plain', '10.44.0.214');
        $group = provisioner_group($project);

        if ($cause === 'other project') {
            provisioner_default(provisioner_app('other'), $node);
        } elseif ($cause === 'inactive') {
            provisioner_default($project, $node, InstanceState::SourceResolved);
        }

        $fakes = bind_task_workspace_fakes();

        if ($cause === 'cold') {
            provisioner_default($project, $node);
            $fakes->copies->failInspect = 'instance.copy_source_cold';
        }

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

        expect($instance?->node_id)->toBe($node->id)
            ->and($instance?->creation)->toBe(InstanceCreation::Repository)
            ->and($instance?->source_instance_id)->toBeNull()
            ->and($instance?->starting_commit)->toBe(str_repeat('a', 40))
            ->and($fakes->copies->fetchedCopies)->toBe(0)
            ->and($fakes->source->calls)->toContain('prepare', 'resolve')
            ->and($group->fresh()?->workspace_creation)->toBe(InstanceCreation::Repository)
            ->and($group->fresh()?->workspace_copy_mode)->toBeNull()
            ->and($group->fresh()?->workspace_fallback_reason)->toBe(TaskWorkspaceProvisioner::SourceUnavailableReason);
    })->with(['missing', 'other project', 'inactive', 'cold']);

    it('falls back to a fresh clone when the copy fails before the workspace exists', function (): void {
        $project = provisioner_app('shop');
        $node = provisioner_node('failed', '10.44.0.215');
        $source = provisioner_default($project, $node);
        $group = provisioner_group($project);
        $fakes = bind_task_workspace_fakes();
        $fakes->copies->failCopy = 'instance.copy_source_changed';
        $fakes->copies->copyStarts = true;

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

        expect($instance?->creation)->toBe(InstanceCreation::Repository)
            ->and($instance?->name)->toBe(TaskWorkspaceName::for($group))
            ->and($instance?->starting_commit)->toBe(str_repeat('a', 40))
            ->and($instance?->source_instance_id)->toBeNull()
            ->and($fakes->copies->fetchedCopies)->toBe(1)
            ->and($fakes->copies->discards)->toBeGreaterThan(0)
            ->and($fakes->source->calls)->toContain('prepare')
            ->and($source->fresh()?->status)->toBe(InstanceState::Active)
            ->and($group->fresh()?->workspace_creation)->toBe(InstanceCreation::Repository)
            ->and($group->fresh()?->workspace_fallback_reason)->toBe(TaskWorkspaceProvisioner::copyFailedReason('instance.copy_source_changed'));
        $this->assertDatabaseCount('instances', 2);
    });

    it('resumes a leftover workspace without copying again', function (): void {
        $project = provisioner_app('orbit');
        $node = provisioner_node('resume', '10.44.0.216');
        provisioner_default($project, $node);
        $group = provisioner_group($project);
        $left = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => TaskWorkspaceName::for($group),
            'checkout_path' => '/srv/orbit/apps/orbit/'.TaskWorkspaceName::for($group),
            'branch_override' => TaskWorkspaceName::for($group),
            'branch' => TaskWorkspaceName::for($group),
            'starting_commit' => str_repeat('e', 40),
            'status' => InstanceState::CheckoutPrepared,
        ]);
        $fakes = bind_task_workspace_fakes();

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

        expect($instance?->id)->toBe($left->id)
            ->and($instance?->status)->toBe(InstanceState::SourceResolved)
            ->and($fakes->copies->fetchedCopies)->toBe(0)
            ->and($fakes->copies->inspections)->toBe(0)
            ->and($fakes->copies->discards)->toBe(0)
            ->and($group->fresh()?->workspace_creation)->toBe(InstanceCreation::Repository)
            ->and($group->fresh()?->workspace_copy_mode)->toBeNull()
            ->and($group->fresh()?->workspace_fallback_reason)->toBeNull();
    });

    it('records the contract tip the copy selected instead of the source head', function (): void {
        $project = provisioner_app('shop');
        $node = provisioner_node('contract', '10.44.0.217');
        provisioner_default($project, $node);
        $group = provisioner_group($project);
        $fakes = bind_task_workspace_fakes();
        $fakes->copies->contractTip = str_repeat('f', 40);

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

        expect($instance?->starting_commit)->toBe(str_repeat('f', 40))
            ->and($instance?->starting_commit)->not->toBe($fakes->copies->head)
            ->and($instance?->starting_commit)->not->toBe($fakes->copies->fetchedTip)
            ->and($instance?->branch)->toBe(TaskWorkspaceName::for($group))
            ->and($fakes->copies->branch)->toBe(TaskWorkspaceName::for($group))
            ->and($fakes->copies->defaultBranch)->toBe('main');
    });

    it('fills workspace evidence when a finished copy stopped before the task stored it', function (): void {
        $project = provisioner_app('orbit');
        $node = provisioner_node('remember', '10.44.0.218');
        $source = provisioner_default($project, $node);
        $group = provisioner_group($project);
        $name = TaskWorkspaceName::for($group);
        $left = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => $name,
            'checkout_path' => '/srv/orbit/apps/orbit/'.$name,
            'branch_override' => $name,
            'branch' => $name,
            'starting_commit' => str_repeat('e', 40),
            'creation' => InstanceCreation::Copy,
            'copy_mode' => 'reflink',
            'source_instance_id' => $source->id,
            'status' => InstanceState::CheckoutPrepared,
        ]);
        $fakes = bind_task_workspace_fakes();

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

        expect($instance?->id)->toBe($left->id)
            ->and($instance?->status)->toBe(InstanceState::SourceResolved)
            ->and($fakes->copies->fetchedCopies)->toBe(0)
            ->and($fakes->copies->discards)->toBe(0)
            ->and($group->fresh()?->workspace_creation)->toBe(InstanceCreation::Copy)
            ->and($group->fresh()?->workspace_copy_mode)->toBe('reflink')
            ->and($group->fresh()?->workspace_fallback_reason)->toBeNull();
    });

    it('retries a reserved copy after removing its partial tree', function (): void {
        $project = provisioner_app('shop');
        $node = provisioner_node('retry-copy', '10.44.0.219');
        $source = provisioner_default($project, $node);
        $group = provisioner_group($project);
        $left = reserved_copy_workspace($project, $node, $group, $source);
        $fakes = bind_task_workspace_fakes();
        $fakes->copies->contractTip = str_repeat('f', 40);

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

        expect($instance?->id)->toBe($left->id)
            ->and($instance?->creation)->toBe(InstanceCreation::Copy)
            ->and($instance?->source_instance_id)->toBe($source->id)
            ->and($instance?->starting_commit)->toBe(str_repeat('f', 40))
            ->and($fakes->copies->fetchedCopies)->toBe(1)
            ->and($fakes->copies->discards)->toBe(1)
            ->and($fakes->copies->branch)->toBe($left->name)
            ->and($group->fresh()?->workspace_creation)->toBe(InstanceCreation::Copy)
            ->and($group->fresh()?->workspace_copy_mode)->toBe('reflink')
            ->and($group->fresh()?->workspace_fallback_reason)->toBeNull();
        $this->assertDatabaseCount('instances', 2);
    });

    it('falls back on the reserved row when the source is no longer eligible', function (): void {
        $project = provisioner_app('shop');
        $node = provisioner_node('retry-plain', '10.44.0.220');
        $source = provisioner_default($project, $node);
        $group = provisioner_group($project);
        $left = reserved_copy_workspace($project, $node, $group, $source);
        $source->update(['status' => InstanceState::SourceResolved]);
        $fakes = bind_task_workspace_fakes();

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

        expect($instance?->id)->toBe($left->id)
            ->and($instance?->creation)->toBe(InstanceCreation::Repository)
            ->and($instance?->source_instance_id)->toBeNull()
            ->and($instance?->starting_commit)->toBe(str_repeat('a', 40))
            ->and($fakes->copies->fetchedCopies)->toBe(0)
            ->and($fakes->copies->discards)->toBe(1)
            ->and($fakes->source->calls)->toContain('prepare')
            ->and($group->fresh()?->workspace_creation)->toBe(InstanceCreation::Repository)
            ->and($group->fresh()?->workspace_fallback_reason)->toBe(TaskWorkspaceProvisioner::SourceUnavailableReason);
        $this->assertDatabaseCount('instances', 2);
    });

    it('falls back on the reserved row when the retried copy fails', function (): void {
        $project = provisioner_app('shop');
        $node = provisioner_node('retry-fail', '10.44.0.221');
        $source = provisioner_default($project, $node);
        $group = provisioner_group($project);
        $left = reserved_copy_workspace($project, $node, $group, $source);
        $fakes = bind_task_workspace_fakes();
        $fakes->copies->failCopy = 'instance.copy_source_changed';
        $fakes->copies->copyStarts = true;

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

        expect($instance?->id)->toBe($left->id)
            ->and($instance?->creation)->toBe(InstanceCreation::Repository)
            ->and($instance?->source_instance_id)->toBeNull()
            ->and($fakes->copies->fetchedCopies)->toBe(1)
            ->and($fakes->copies->discards)->toBe(2)
            ->and($fakes->source->calls)->toContain('prepare')
            ->and($group->fresh()?->workspace_fallback_reason)->toBe(TaskWorkspaceProvisioner::copyFailedReason('instance.copy_source_changed'));
        $this->assertDatabaseCount('instances', 2);
    });

    it('leaves a reserved copy in place when its partial tree cannot be removed', function (): void {
        $project = provisioner_app('shop');
        $node = provisioner_node('stuck', '10.44.0.222');
        $source = provisioner_default($project, $node);
        $group = provisioner_group($project);
        $left = reserved_copy_workspace($project, $node, $group, $source);
        $fakes = bind_task_workspace_fakes();
        $fakes->copies->failDiscard = true;

        expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true)))->toBeNull()
            ->and($left->fresh()?->status)->toBe(InstanceState::Reserved)
            ->and($left->fresh()?->creation)->toBe(InstanceCreation::Copy)
            ->and($left->fresh()?->source_instance_id)->toBe($source->id)
            ->and($fakes->copies->fetchedCopies)->toBe(0)
            ->and($fakes->source->calls)->not->toContain('prepare')
            ->and($group->fresh()?->workspace_creation)->toBe(InstanceCreation::Copy)
            ->and($group->fresh()?->workspace_fallback_reason)->toBe(TaskWorkspaceProvisioner::copyFailedReason('instance.copy_failed'));
        $this->assertDatabaseCount('instances', 2);

        $fakes->copies->failDiscard = false;
        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

        expect($instance?->id)->toBe($left->id)
            ->and($instance?->creation)->toBe(InstanceCreation::Copy)
            ->and($group->fresh()?->workspace_creation)->toBe(InstanceCreation::Copy)
            ->and($group->fresh()?->workspace_copy_mode)->toBe('reflink')
            ->and($group->fresh()?->workspace_fallback_reason)->toBeNull();
    });

    it('does not reserve a fresh clone when the failed copy cannot be removed', function (): void {
        $project = provisioner_app('shop');
        $node = provisioner_node('keep-row', '10.44.0.223');
        $source = provisioner_default($project, $node);
        $group = provisioner_group($project);
        $fakes = bind_task_workspace_fakes();
        $fakes->copies->failCopy = 'instance.copy_failed';
        $fakes->copies->copyStarts = true;
        $fakes->copies->failDiscard = true;

        expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true)))->toBeNull();

        $left = Instance::query()->where('name', TaskWorkspaceName::for($group))->first();

        expect($left)->toBeInstanceOf(Instance::class)
            ->and($left?->status)->toBe(InstanceState::Reserved)
            ->and($left?->creation)->toBe(InstanceCreation::Copy)
            ->and($left?->source_instance_id)->toBe($source->id)
            ->and($fakes->copies->fetchedCopies)->toBe(1)
            ->and($fakes->source->calls)->not->toContain('prepare')
            ->and($group->fresh()?->workspace_creation)->toBe(InstanceCreation::Copy)
            ->and($group->fresh()?->workspace_fallback_reason)->toBe(TaskWorkspaceProvisioner::copyFailedReason('instance.copy_failed'));
        $this->assertDatabaseCount('instances', 2);
    });

    it('fresh-clones a retried copy that fails after the row is checkout prepared', function (): void {
        $project = provisioner_app('shop');
        $node = provisioner_node('inspect-fail', '10.44.0.224');
        $source = provisioner_default($project, $node);
        $group = provisioner_group($project);
        $left = reserved_copy_workspace($project, $node, $group, $source);
        copied_workspace_attachments($source);
        $fakes = bind_task_workspace_fakes();
        $fakes->source->failInspectResolvedTimes = 1;

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

        expect($instance?->id)->toBe($left->id)
            ->and($instance?->status)->toBe(InstanceState::SourceResolved)
            ->and($instance?->creation)->toBe(InstanceCreation::Repository)
            ->and($instance?->source_instance_id)->toBeNull()
            ->and($instance?->copy_mode)->toBeNull()
            ->and($instance?->branch)->toBe($left->name)
            ->and($instance?->starting_commit)->toBe(str_repeat('a', 40))
            ->and($fakes->source->calls)->toContain('prepare')
            ->and(InstanceEnvironmentValue::query()->where('instance_id', $left->id)->exists())->toBeFalse()
            ->and(DatabaseConnectionTarget::query()->where('instance_id', $left->id)->exists())->toBeFalse()
            ->and(InstanceEnvironmentValue::query()->where('instance_id', $source->id)->exists())->toBeTrue()
            ->and(DatabaseConnectionTarget::query()->where('instance_id', $source->id)->exists())->toBeTrue()
            ->and($group->fresh()?->workspace_creation)->toBe(InstanceCreation::Repository)
            ->and($group->fresh()?->workspace_fallback_reason)->toBe(TaskWorkspaceProvisioner::copyFailedReason('instance.source_identity_invalid'));
    });

    it('fresh-clones a copied checkout that is already gone', function (): void {
        $project = provisioner_app('shop');
        $node = provisioner_node('gone-copy', '10.44.0.225');
        $source = provisioner_default($project, $node);
        $group = provisioner_group($project);
        $name = TaskWorkspaceName::for($group);
        $left = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => $name,
            'source_layout' => 'checkout',
            'checkout_path' => '/srv/orbit/apps/shop/'.$name,
            'branch_override' => $name,
            'branch' => $name,
            'starting_commit' => str_repeat('e', 40),
            'creation' => InstanceCreation::Copy,
            'copy_mode' => 'reflink',
            'source_instance_id' => $source->id,
            'status' => InstanceState::CheckoutPrepared,
        ]);
        copied_workspace_attachments($left);
        $fakes = bind_task_workspace_fakes();
        $fakes->source->failInspectPreparedTimes = 1;

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

        expect($instance?->id)->toBe($left->id)
            ->and($instance?->creation)->toBe(InstanceCreation::Repository)
            ->and($instance?->status)->toBe(InstanceState::SourceResolved)
            ->and($instance?->source_instance_id)->toBeNull()
            ->and($fakes->source->calls)->toContain('prepare')
            ->and($fakes->copies->fetchedCopies)->toBe(0)
            ->and(InstanceEnvironmentValue::query()->where('instance_id', $left->id)->exists())->toBeFalse()
            ->and(DatabaseConnectionTarget::query()->where('instance_id', $left->id)->exists())->toBeFalse()
            ->and($group->fresh()?->workspace_fallback_reason)->toBe(TaskWorkspaceProvisioner::copyFailedReason('instance.source_identity_invalid'));
    });

    it('keeps a present copy when inspection fails and the tree is still there', function (): void {
        $project = provisioner_app('orbit');
        $node = provisioner_node('present-copy', '10.44.0.226');
        $source = provisioner_default($project, $node);
        $group = provisioner_group($project);
        $name = TaskWorkspaceName::for($group);
        $left = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => $name,
            'source_layout' => 'checkout',
            'checkout_path' => '/srv/orbit/apps/orbit/'.$name,
            'branch_override' => $name,
            'branch' => $name,
            'starting_commit' => str_repeat('e', 40),
            'creation' => InstanceCreation::Copy,
            'copy_mode' => 'reflink',
            'source_instance_id' => $source->id,
            'status' => InstanceState::SourceResolved,
        ]);
        $group->update([
            'workspace_creation' => InstanceCreation::Copy,
            'workspace_copy_mode' => 'reflink',
            'workspace_fallback_reason' => null,
        ]);
        $fakes = bind_task_workspace_fakes();
        $fakes->source->failInspectPreparedTimes = 1;
        $fakes->copies->failDiscard = true;

        expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false)))->toBeNull()
            ->and($left->fresh()?->status)->toBe(InstanceState::SourceResolved)
            ->and($left->fresh()?->creation)->toBe(InstanceCreation::Copy)
            ->and($left->fresh()?->copy_mode)->toBe('reflink')
            ->and($fakes->source->calls)->not->toContain('prepare')
            ->and($group->fresh()?->workspace_creation)->toBe(InstanceCreation::Copy)
            ->and($group->fresh()?->workspace_copy_mode)->toBe('reflink')
            ->and($group->fresh()?->workspace_fallback_reason)->toBeNull();

        $fakes->copies->failDiscard = false;
        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

        expect($instance?->id)->toBe($left->id)
            ->and($instance?->creation)->toBe(InstanceCreation::Copy)
            ->and($instance?->copy_mode)->toBe('reflink')
            ->and($group->fresh()?->workspace_creation)->toBe(InstanceCreation::Copy)
            ->and($group->fresh()?->workspace_copy_mode)->toBe('reflink')
            ->and($group->fresh()?->workspace_fallback_reason)->toBeNull();
    });
});

function copied_workspace_attachments(Instance $instance): void
{
    $instance->environmentValues()->create([
        'env_key' => 'APP_NAME',
        'env_value' => 'Copied',
    ]);
    $connection = DatabaseConnection::query()->create([
        'slug' => 'app-'.$instance->id,
        'driver' => DatabaseDriver::Mysql,
        'node_id' => $instance->node_id,
        'host' => 'db.internal',
        'port' => 3306,
        'database' => 'app',
        'username' => 'app',
        'password' => 'secret-value',
    ]);
    DatabaseConnectionTarget::query()->create([
        'database_connection_id' => $connection->id,
        'instance_id' => $instance->id,
        'prefix' => 'DB',
    ]);
}

function reserved_copy_workspace(Project $project, Node $node, Task $group, Instance $source): Instance
{
    $name = TaskWorkspaceName::for($group);

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/orbit/apps/'.$project->slug.'/'.$name,
        'branch_override' => $name,
        'creation' => InstanceCreation::Copy,
        'source_instance_id' => $source->id,
        'status' => InstanceState::Reserved,
    ]);
}

/**
 * @return array{0: Task, 1: Instance, 2: string}
 */
function orbit_workspace_clone(): array
{
    $project = provisioner_app('orbit', type: ProjectType::Monorepo);
    provisioner_node('orbit-clone', '10.44.0.181');
    $group = provisioner_group($project, 'Clone');
    bind_task_workspace_fakes();
    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));
    expect($instance)->toBeInstanceOf(Instance::class)
        ->and($instance->status)->toBe(InstanceState::SourceResolved)
        ->and($instance->routes()->count())->toBe(0);

    $checkout = sys_get_temp_dir().'/orbit-task-'.$instance->id;
    if (! is_dir($checkout)) {
        mkdir($checkout);
    }
    file_put_contents($checkout.'/KEEP', 'clone');
    $instance->update(['checkout_path' => $checkout]);

    return [$group->fresh(['project', 'taskable']) ?? $group, $instance->fresh() ?? $instance, $checkout];
}

function bind_checkout_remover(): void
{
    app()->instance(InstanceRemover::class, new class implements InstanceRemover
    {
        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            expect($force)->toBeTrue();
            $path = $instance->checkout_path;
            if (is_string($path) && is_file($path.'/KEEP')) {
                unlink($path.'/KEEP');
            }
            if (is_string($path) && is_dir($path)) {
                rmdir($path);
            }
            $instance->delete();

            return new InstanceRemoval;
        }
    });
}

function forget_checkout(string $checkout): void
{
    if (is_file($checkout.'/KEEP')) {
        unlink($checkout.'/KEEP');
    }
    if (is_dir($checkout)) {
        rmdir($checkout);
    }
}

it('removes the orbit workspace clone on cancel and on complete', function (string $operation): void {
    app(TaskExtensionState::class)->enable();
    bind_task_node_reachability();
    [$group, $instance, $checkout] = orbit_workspace_clone();
    if ($operation !== 'unattached') {
        $group->taskable()->associate($instance);
    }
    $group->status = $operation === 'complete' ? TaskGroupStatus::Settling : TaskGroupStatus::Running;
    if ($operation === 'complete') {
        $group->pr_url = 'https://github.com/nckrtl/orbit/pull/120';
    }
    $group->save();
    bind_checkout_remover();

    try {
        $result = $operation === 'complete'
            ? app(CompleteTaskGroupAction::class)->execute($group)
            : app(CancelTaskGroupAction::class)->execute($group);

        expect($result->status)->toBe($operation === 'complete' ? TaskGroupStatus::Completed : TaskGroupStatus::Cancelled)
            ->and($result->taskable_id)->toBeNull()
            ->and(is_dir($checkout))->toBeFalse()
            ->and(Instance::query()->whereKey($instance->id)->exists())->toBeFalse();
    } finally {
        forget_checkout($checkout);
    }
})->with(['cancel', 'complete', 'unattached']);

it('keeps the source-resolved orbit clone and asks for assistance when removal is refused', function (string $operation): void {
    app(TaskExtensionState::class)->enable();
    bind_task_node_reachability();
    [$group, $instance, $checkout] = orbit_workspace_clone();
    if ($operation !== 'unattached') {
        $group->taskable()->associate($instance);
    }
    $group->status = $operation === 'complete' ? TaskGroupStatus::Settling : TaskGroupStatus::Running;
    $group->save();
    app()->instance(InstanceRemover::class, new class implements InstanceRemover
    {
        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            throw new ResourceOperationException('instance.force_failed', 'The checkout could not be inspected.', 409);
        }
    });

    try {
        if ($operation === 'complete') {
            $completed = app(CompleteTaskGroupAction::class)->execute($group);

            expect($completed->status)->toBe(TaskGroupStatus::Completed)
                ->and($completed->taskable_id)->toBe($instance->id)
                ->and($completed->assistance_requested)->toBeTrue()
                ->and($completed->assistance_reason)->toBe(RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The checkout could not be inspected.')
                ->and(is_dir($checkout))->toBeTrue()
                ->and(file_get_contents($checkout.'/KEEP'))->toBe('clone')
                ->and(Instance::query()->whereKey($instance->id)->exists())->toBeTrue();

            return;
        }

        expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
            ->toThrow(ResourceOperationException::class, 'The checkout could not be inspected.');

        $fresh = $group->fresh();
        expect(is_dir($checkout))->toBeTrue()
            ->and(file_get_contents($checkout.'/KEEP'))->toBe('clone')
            ->and(Instance::query()->whereKey($instance->id)->exists())->toBeTrue()
            ->and($fresh?->taskable_id)->toBe($operation === 'unattached' ? null : $instance->id)
            ->and($fresh?->assistance_requested)->toBeTrue()
            ->and($fresh?->assistance_reason)->toBe(RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The checkout could not be inspected.')
            ->and($fresh?->status)->toBe(TaskGroupStatus::Running);
    } finally {
        forget_checkout($checkout);
    }
})->with(['cancel', 'complete', 'unattached']);
