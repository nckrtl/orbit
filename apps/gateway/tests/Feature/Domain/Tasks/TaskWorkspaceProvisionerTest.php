<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Instances\InstanceSourceLayout;
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
use App\Domain\Tasks\InstanceProvisionFailure;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCeilings;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Domain\Tasks\TaskWorkspaceTopology;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectNodeExclusion;
use App\Models\Task;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

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
        'name' => 'pi-server',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/home/orbit',
        'runtime_config' => ['command' => ['/home/orbit/.local/bin/pi-server', 'serve', "--host={$ip}", '--port=3774']],
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
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
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

        public ?Closure $onPrepare = null;

        public function prepare(Instance $instance, bool $allowExisting): void
        {
            $this->calls[] = 'prepare';
            if ($this->onPrepare !== null) {
                ($this->onPrepare)($instance, $allowExisting);
            }
        }

        public function inspectPrepared(Instance $instance): void
        {
            $this->calls[] = 'inspect-prepared';
        }

        public function resolve(Instance $instance): DevelopmentSourceResolution
        {
            $this->calls[] = 'resolve';

            return new DevelopmentSourceResolution($instance->name, str_repeat('a', 40));
        }

        public function inspectResolved(Instance $instance): DevelopmentSourceResolution
        {
            $this->calls[] = 'inspect-resolved';

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

    app()->instance(ManagedUserAccountResolver::class, $accounts);
    app()->instance(InstanceDestinationGuard::class, $destination);
    app()->instance(DevelopmentInstanceSourceLifecycle::class, $source);
    app()->instance(DevelopmentInstanceProvisioner::class, $development);

    return (object) ['source' => $source, 'development' => $development];
}

it('task workspace stamps source_prepare_id before preparing source', function (): void {
    $project = provisioner_app('prepare-id');
    provisioner_node('prepare-id-dev', '10.44.0.138');
    $group = provisioner_group($project);
    $fakes = bind_task_workspace_fakes();
    $reservation = null;
    $fakes->source->onPrepare = static function (Instance $instance, bool $allowExisting) use (&$reservation): void {
        $reservation = $instance->fresh();
    };

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($reservation?->status)->toBe(InstanceState::Reserved);
    expect($reservation?->source_prepare_id)->not->toBeNull()->toBeString();
    expect(Str::isUuid($reservation->source_prepare_id, version: 4))->toBeTrue();
    expect($instance?->fresh()?->source_prepare_id)->toBe($reservation->source_prepare_id);
    expect($instance?->status)->toBe(InstanceState::SourceResolved);
});

it('provisions a workspace without an automatic root dependency copy', function (): void {
    $project = provisioner_app('acme');
    $node = provisioner_node('acme-dev', '10.44.0.111');
    Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'checkout_path' => '/srv/orbit/apps/acme/default',
        'branch' => 'main',
        'starting_commit' => str_repeat('b', 40),
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $group = provisioner_group($project, 'Copied');
    bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance?->status)->toBe(InstanceState::SourceResolved);
});

it('never acquires a topology when provisioning a new or reused workspace', function (): void {
    $project = provisioner_app('topology');
    provisioner_node('topology-dev', '10.44.0.112');
    $group = provisioner_group($project, 'Topology');
    bind_task_workspace_fakes();
    $topology = app(TaskWorkspaceTopology::class);

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));
    $group->taskable()->associate($instance);
    $group->save();
    $topology->fails = true;
    $reused = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group->fresh(['taskable']) ?? $group, false));

    expect($reused?->id)->toBe($instance?->id)
        ->and($topology->calls)->toBe([]);
});

it('leaves a group reserved when no app-dev Node can take the workspace', function (): void {
    $project = provisioner_app('lonely');
    $group = provisioner_group($project);
    bind_task_workspace_fakes();

    expect(app(InstanceProvisioning::class)->provision(new InstanceProvisionIntent($group, true)))
        ->toBeInstanceOf(InstanceProvisionFailure::class);
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
        ->and($fakes->source->calls)->toBe(['prepare', 'inspect-prepared', 'resolve', 'inspect-prepared', 'inspect-resolved', 'inspect-prepared'])
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

it('returns a failure when a visitable App lacks a web root', function (): void {
    $project = provisioner_app('bare', null);
    provisioner_node('bare-dev', '10.44.0.104');
    $group = provisioner_group($project);
    bind_task_workspace_fakes();

    expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true)))
        ->toBeInstanceOf(InstanceProvisionFailure::class);
});

it('returns a failure when destination occupation refuses the checkout', function (): void {
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
        ->toBeInstanceOf(InstanceProvisionFailure::class);
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

it('skips a non-Pi app-dev Node even when it has the lower id', function (): void {
    $project = provisioner_app('placement');
    $incapable = provisioner_node('no-pi', '10.44.0.110');
    $incapable->processes()->delete();
    $capable = provisioner_node('with-pi', '10.44.0.111');
    $group = provisioner_group($project);
    bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance?->node_id)->toBe($capable->id);
    $this->assertDatabaseMissing('instances', ['node_id' => $incapable->id]);
});

it('reports a capacity wait without creating a workspace when every capable Node is full', function (bool $otherNodeHasRoom): void {
    $project = provisioner_app('full');
    $incapable = provisioner_node('no-pi', '10.44.0.110');
    $incapable->processes()->delete();
    $capable = provisioner_node('full-pi', '10.44.0.111');
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

it('places Pi roles on a Node with Pi rather than one with only T3', function (): void {
    $project = provisioner_app('pi-placement');
    $t3Only = provisioner_node('t3-only', '10.44.0.113');
    $t3Only->processes()->update(['name' => 't3-code']);
    $pi = provisioner_node('pi-only', '10.44.0.114');
    $group = provisioner_group($project);
    bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance?->node_id)->toBe($pi->id);
    $this->assertDatabaseMissing('instances', ['node_id' => $t3Only->id]);
});

it('returns a failure when no Node allows the implementer driver', function (): void {
    $project = provisioner_app('no-pi');
    provisioner_node('t3-only', '10.44.0.115')->processes()->update(['name' => 't3-code']);
    $group = provisioner_group($project);
    $fakes = bind_task_workspace_fakes();

    expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group->fresh() ?? $group, false)))->toBeInstanceOf(InstanceProvisionFailure::class)
        ->and($fakes->source->calls)->toBe([]);
});

it('returns a failure when the app-dev Node has no usable Pi process', function (string $reason): void {
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

    expect($instance)->toBeInstanceOf(InstanceProvisionFailure::class)
        ->and($fakes->source->calls)->toBe([]);
    $this->assertDatabaseCount('instances', 0);
})->with(['missing', 'unrelated', 'failed', 'stopped', 'no-address', 'empty-address']);

it('resumes a reserved worktree reclaim with no starting commit', function (): void {
    $project = provisioner_app('reclaim');
    $node = provisioner_node('reclaim-dev', '10.44.0.135');
    $group = provisioner_group($project);
    $sandbox = sys_get_temp_dir().'/orbit-reserved-reclaim-'.Str::uuid();
    $seed = $sandbox.'/default';
    $checkout = $sandbox.'/'.TaskWorkspaceName::for($group);
    $files = new Filesystem;
    $files->makeDirectory($seed, 0o755, true);

    try {
        foreach ([
            ['git', 'init', '--initial-branch=main', $seed],
            ['git', '-C', $seed, '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '--allow-empty', '-m', 'Seed'],
            ['git', '-C', $seed, 'worktree', 'add', '--detach', $checkout, 'HEAD'],
        ] as $command) {
            (new Process($command))->mustRun();
        }
        $commit = trim((new Process(['git', '-C', $seed, 'rev-parse', 'HEAD']))->mustRun()->getOutput());
        $left = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => TaskWorkspaceName::for($group),
            'checkout_path' => $checkout,
            'branch_override' => TaskWorkspaceName::for($group),
            'source_layout' => InstanceSourceLayout::Worktree,
            'seed_repository' => $seed,
            'seed_commit' => $commit,
            'starting_commit' => null,
            'source_prepare_id' => null,
            'task_workspace_routed' => false,
            'status' => InstanceState::Reserved,
        ]);
        $fakes = bind_task_workspace_fakes();
        // Model the remote prepare contract: an existing checkout refuses unless explicitly allowed.
        $fakes->source->onPrepare = static function (Instance $instance, bool $allowExisting): void {
            if (is_file($instance->checkout_path.'/.git') && ! $allowExisting) {
                throw new ResourceOperationException('instance.clone_failed', 'Checkout already exists.', 409);
            }
        };

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

        expect($instance?->id)->toBe($left->id)
            ->and($instance?->status)->toBe(InstanceState::SourceResolved)
            ->and($instance?->starting_commit)->toBe(str_repeat('a', 40))
            ->and($instance?->source_prepare_id)->toBeNull();
        expect($fakes->source->calls)->toBe(['prepare', 'inspect-prepared', 'resolve', 'inspect-prepared', 'inspect-resolved', 'inspect-prepared']);
        expect(is_file($checkout.'/.git'))->toBeTrue();
        $this->assertDatabaseCount('instances', 1);
    } finally {
        $files->deleteDirectory($sandbox);
    }
});

it('refuses a reserved worktree reclaim when source identity does not match', function (): void {
    $project = provisioner_app('foreign-reclaim');
    $node = provisioner_node('foreign-dev', '10.44.0.137');
    $group = provisioner_group($project);
    $left = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => TaskWorkspaceName::for($group),
        'checkout_path' => '/srv/orbit/apps/foreign-reclaim/'.TaskWorkspaceName::for($group),
        'branch_override' => TaskWorkspaceName::for($group),
        'source_layout' => InstanceSourceLayout::Worktree,
        'seed_repository' => '/srv/orbit/apps/foreign-reclaim/default',
        'seed_commit' => str_repeat('b', 40),
        'status' => InstanceState::Reserved,
    ]);
    $fakes = bind_task_workspace_fakes();
    $fakes->source->onPrepare = static function (Instance $instance, bool $allowExisting): void {
        throw new ResourceOperationException('instance.clone_failed', 'Source identity does not match.', 409);
    };

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance)->toBeInstanceOf(InstanceProvisionFailure::class)
        ->and($instance->cause)->toBe(ResourceOperationException::class.' [instance.clone_failed]: Source identity does not match.');
    expect($left->refresh()->status)->toBe(InstanceState::Reserved)
        ->and($left->starting_commit)->toBeNull();
    expect($fakes->source->calls)->toBe(['prepare']);
    $this->assertModelExists($left);
});

it('does not allow_existing task provision for a newly reserved Instance', function (): void {
    $project = provisioner_app('fresh-reclaim');
    provisioner_node('fresh-dev', '10.44.0.136');
    $group = provisioner_group($project);
    $fakes = bind_task_workspace_fakes();
    $fakes->source->onPrepare = static function (Instance $instance, bool $allowExisting): void {
        if ($allowExisting) {
            throw new ResourceOperationException('instance.clone_failed', 'Cannot adopt an existing checkout on first prepare.', 409);
        }
    };

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance?->status)->toBe(InstanceState::SourceResolved);
});

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

        expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false)))->toBeInstanceOf(InstanceProvisionFailure::class)
            ->and($lookalike->fresh()?->branch_override)->toBe('feature-x')
            ->and($lookalike->fresh()?->status)->toBe(InstanceState::SourceResolved);
        $this->assertDatabaseCount('instances', 1);
    });
});

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

it('releases the group topology before it removes the workspace, and keeps the workspace when that fails', function (bool $fails): void {
    app(TaskExtensionState::class)->enable();
    bind_task_node_reachability();
    [$group, $instance, $checkout] = orbit_workspace_clone();
    $group->taskable()->associate($instance);
    $group->status = TaskGroupStatus::Running;
    $group->save();
    bind_checkout_remover();
    $topology = app(TaskWorkspaceTopology::class);
    $topology->fails = $fails;

    try {
        try {
            app(CancelTaskGroupAction::class)->execute($group);
        } catch (Throwable) {
        }

        expect(end($topology->calls))->toBe(['release', $instance->id, $group->id])
            ->and(Instance::query()->whereKey($instance->id)->exists())->toBe($fails);
    } finally {
        forget_checkout($checkout);
    }
})->with(['released' => false, 'release fails' => true]);

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
                ->and($completed->assistance_requested)->toBeFalse()
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
