<?php

declare(strict_types=1);

use App\Actions\Doctor\InstanceDoctorProbe;
use App\Actions\Routes\CreateRouteAction;
use App\Actions\Tasks\CancelTaskGroupAction;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\InstanceInspectionData;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\DevelopmentSourceProfile;
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
use App\Domain\Projects\ProjectUpdateProjectionMutator;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\InstanceProvisionFailure;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceLifecycle;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Infrastructure\Instances\NativeDevelopmentInstanceProvisioner;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeProjectUpdateProjectionMutator;

it('provisions from the Project setting for an orbit slug and another slug', function (string $slug, bool $routed): void {
    $project = routing_mode_project($slug, $routed);
    $node = routing_mode_node($slug);
    $group = routing_mode_group($project);
    routing_mode_bind_boundaries();

    $intent = InstanceProvisionIntent::for($group);
    $instance = app(TaskWorkspaceProvisioner::class)->provision($intent);

    expect($intent->visitable)->toBe($routed)
        ->and($instance)->toBeInstanceOf(Instance::class)
        ->and($instance->task_workspace_routed)->toBe($routed)
        ->and($instance->status)->toBe($routed ? InstanceState::Active : InstanceState::SourceResolved)
        ->and($instance->root)->toBe($routed ? 'public' : null)
        ->and($instance->routes()->count())->toBe($routed ? 1 : 0)
        ->and($instance->node_id)->toBe($node->id);
    if ($routed) {
        expect($instance->routes()->sole()->status)->toBe(RouteStatus::Active);
    }
})->with([
    'orbit routed' => ['orbit', true],
    'orbit unrouted' => ['orbit', false],
    'shop routed' => ['shop', true],
    'shop unrouted' => ['shop', false],
]);

it('keeps an existing workspace on its recorded mode when the setting changes', function (string $slug, bool $routed): void {
    $project = routing_mode_project($slug, $routed);
    routing_mode_node($slug.'-kept');
    $group = routing_mode_group($project);
    routing_mode_bind_boundaries();
    $instance = app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for($group));
    expect($instance)->toBeInstanceOf(Instance::class);
    $routeIds = $instance->routes()->pluck('routes.id')->sort()->values()->all();
    $project->update(['task_workspace_routed' => ! $routed]);

    $resumed = app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for($group->fresh(['project']) ?? $group));

    expect($resumed)->toBeInstanceOf(Instance::class)
        ->and($resumed->id)->toBe($instance->id)
        ->and($resumed->task_workspace_routed)->toBe($routed)
        ->and($resumed->status)->toBe($routed ? InstanceState::Active : InstanceState::SourceResolved)
        ->and($resumed->routes()->pluck('routes.id')->sort()->values()->all())->toBe($routeIds)
        ->and(TaskWorkspaceLifecycle::settledState($resumed->fresh() ?? $resumed))
        ->toBe($routed ? InstanceState::Active : InstanceState::SourceResolved);
})->with([
    'orbit was routed' => ['orbit', true],
    'orbit was unrouted' => ['orbit', false],
    'shop was routed' => ['shop', true],
    'shop was unrouted' => ['shop', false],
]);

it('finishes a routed workspace that stopped early even after routing is turned off', function (): void {
    $project = routing_mode_project('orbit', false);
    $node = routing_mode_node('orbit-resume');
    $group = routing_mode_group($project);
    $name = TaskWorkspaceName::for($group);
    Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/srv/orbit/apps/orbit/'.$name,
        'root' => 'public',
        'branch_override' => $name,
        'task_workspace_routed' => true,
        'status' => InstanceState::Reserved,
    ]);
    routing_mode_bind_boundaries();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for($group));

    expect($instance)->toBeInstanceOf(Instance::class)
        ->and($instance->task_workspace_routed)->toBeTrue()
        ->and($instance->status)->toBe(InstanceState::Active)
        ->and($instance->routes()->sole()->status)->toBe(RouteStatus::Active)
        ->and($project->fresh()->task_workspace_routed)->toBeFalse();
});

it('does not route an unrouted workspace that stopped early after routing is turned on', function (): void {
    $project = routing_mode_project('shop', true);
    $node = routing_mode_node('shop-resume');
    $group = routing_mode_group($project);
    $name = TaskWorkspaceName::for($group);
    Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/srv/orbit/apps/shop/'.$name,
        'branch_override' => $name,
        'task_workspace_routed' => false,
        'status' => InstanceState::SourceResolved,
        'branch' => $name,
        'starting_commit' => str_repeat('b', 40),
    ]);
    routing_mode_bind_boundaries();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for($group));

    expect($instance)->toBeInstanceOf(Instance::class)
        ->and($instance->task_workspace_routed)->toBeFalse()
        ->and($instance->status)->toBe(InstanceState::SourceResolved)
        ->and($instance->routes()->count())->toBe(0)
        ->and($project->fresh()->task_workspace_routed)->toBeTrue();
});

it('resumes an unrouted workspace after routing is turned on when the Project root cannot serve', function (?string $root): void {
    $project = routing_mode_project('shop', true, ProjectType::LaravelApp, $root);
    $node = routing_mode_node($root === null ? 'shop-missing-root' : 'shop-dot-root');
    $group = routing_mode_group($project);
    $name = TaskWorkspaceName::for($group);
    Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/srv/orbit/apps/shop/'.$name,
        'branch_override' => $name,
        'task_workspace_routed' => false,
        'status' => InstanceState::SourceResolved,
        'branch' => $name,
        'starting_commit' => str_repeat('b', 40),
    ]);
    routing_mode_bind_boundaries();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for($group));

    expect($instance)->toBeInstanceOf(Instance::class)
        ->and($instance->task_workspace_routed)->toBeFalse()
        ->and($instance->status)->toBe(InstanceState::SourceResolved)
        ->and($instance->routes()->count())->toBe(0)
        ->and($project->fresh()->task_workspace_routed)->toBeTrue();
})->with([
    'missing root' => [null],
    'repository root' => ['.'],
]);

it('resumes an unrecorded unrouted workspace from its missing root instead of the current setting', function (): void {
    $project = routing_mode_project('orbit', true, ProjectType::LaravelApp, null);
    $node = routing_mode_node('orbit-unrecorded');
    $group = routing_mode_group($project);
    $name = TaskWorkspaceName::for($group);
    Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/srv/orbit/apps/orbit/'.$name,
        'branch_override' => $name,
        'status' => InstanceState::SourceResolved,
        'branch' => $name,
        'starting_commit' => str_repeat('c', 40),
    ]);
    routing_mode_bind_boundaries();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for($group));

    expect($instance)->toBeInstanceOf(Instance::class)
        ->and($instance->task_workspace_routed)->toBeFalse()
        ->and($instance->status)->toBe(InstanceState::SourceResolved)
        ->and($instance->routes()->count())->toBe(0);
});

it('keeps workspace routes and settled state after a rename and both setting changes', function (): void {
    $this->markAsGateway(Node::query()->create([
        'name' => 'routing-operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->fakeRepositoryBranches();
    app()->instance(ProjectUpdateProjectionMutator::class, new FakeProjectUpdateProjectionMutator);
    $routedProject = routing_mode_project('shop', true);
    $unroutedProject = routing_mode_project('orbit', false);
    $node = routing_mode_node('rename');
    routing_mode_bind_boundaries();
    $routed = app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for(routing_mode_group($routedProject)));
    $unrouted = app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for(routing_mode_group($unroutedProject)));
    $ordinary = Instance::query()->create([
        'project_id' => $routedProject->id,
        'node_id' => $node->id,
        'name' => 'default',
        'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/srv/orbit/apps/shop/default',
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('c', 40),
        'status' => InstanceState::Active,
    ]);
    $ordinaryRoute = app(CreateRouteAction::class)->ensureForInstance($ordinary, null);
    expect($routed)->toBeInstanceOf(Instance::class)
        ->and($unrouted)->toBeInstanceOf(Instance::class);

    $this->patchJson('/api/v1/projects/'.$routedProject->id, ['task_workspace_routed' => false])->assertOk();
    $this->patchJson('/api/v1/projects/'.$unroutedProject->id, ['task_workspace_routed' => true])->assertOk();
    $this->patchJson('/api/v1/projects/'.$routedProject->id, ['slug' => 'renamed-shop'])->assertOk();
    $this->patchJson('/api/v1/projects/'.$unroutedProject->id, ['slug' => 'renamed-orbit'])->assertOk();
    $this->patchJson('/api/v1/projects/'.$routedProject->id, ['task_workspace_routed' => true])->assertOk();
    $this->patchJson('/api/v1/projects/'.$unroutedProject->id, ['task_workspace_routed' => false])->assertOk();

    $routed = $routed->fresh() ?? $routed;
    $unrouted = $unrouted->fresh() ?? $unrouted;
    $ordinary = $ordinary->fresh() ?? $ordinary;
    DB::table('instances')->whereIn('id', [$routed->id, $unrouted->id, $ordinary->id])
        ->update(['updated_at' => now()->subMinutes(InstanceDoctorProbe::StuckProvisioningMinutes + 1)]);
    $report = new InstanceDoctorProbe(routing_mode_healthy_inspector())->inspect(routing_mode_context($node));

    expect($report->issues)->toBe([])
        ->and($routed->task_workspace_routed)->toBeTrue()
        ->and($routed->status)->toBe(InstanceState::Active)
        ->and($routed->routes()->count())->toBe(1)
        ->and($unrouted->task_workspace_routed)->toBeFalse()
        ->and($unrouted->status)->toBe(InstanceState::SourceResolved)
        ->and($unrouted->routes()->count())->toBe(0)
        ->and($ordinary->task_workspace_routed)->toBeNull()
        ->and($ordinary->status)->toBe(InstanceState::Active)
        ->and($ordinary->routes()->count())->toBe(1)
        ->and($ordinary->routes()->sole()->id)->not->toBe($ordinaryRoute->id)
        ->and($routedProject->fresh()->slug)->toBe('renamed-shop')
        ->and($unroutedProject->fresh()->slug)->toBe('renamed-orbit');
});

it('refuses a routed workspace whose root cannot serve and still creates an unrouted one', function (string $slug, ProjectType $type, ?string $root): void {
    $routed = routing_mode_project($slug, true, $type, $root);
    routing_mode_node($slug.'-root');
    routing_mode_bind_boundaries();

    expect(app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for(routing_mode_group($routed))))
        ->toBeInstanceOf(InstanceProvisionFailure::class)
        ->and(Instance::query()->where('project_id', $routed->id)->exists())->toBeFalse();

    $unrouted = routing_mode_project($slug.'-plain', false, $type, $root);
    $instance = app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for(routing_mode_group($unrouted)));

    expect($instance)->toBeInstanceOf(Instance::class)
        ->and($instance->status)->toBe(InstanceState::SourceResolved)
        ->and($instance->root)->toBeNull()
        ->and($instance->routes()->count())->toBe(0)
        ->and($instance->task_workspace_routed)->toBeFalse();
})->with([
    'orbit laravel root dot' => ['orbit', ProjectType::LaravelApp, '.'],
    'shop laravel missing root' => ['shop', ProjectType::LaravelApp, null],
    'orbit monorepo root dot' => ['orbit-mono', ProjectType::Monorepo, '.'],
]);

it('still rejects a Route for an ordinary Instance whose root cannot serve', function (): void {
    $project = routing_mode_project('orbit', false, ProjectType::NodePackage, '.');
    $node = routing_mode_node('ordinary-root');
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/srv/orbit/apps/orbit/default',
        'branch' => 'main',
        'starting_commit' => str_repeat('d', 40),
        'status' => InstanceState::Active,
    ]);

    expect(fn () => app(CreateRouteAction::class)->ensureForInstance($instance, null))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('route.target_web_root_unsupported')
                ->and($exception->status)->toBe(409);
        });
    expect(Route::query()->count())->toBe(0)
        ->and($instance->fresh()->task_workspace_routed)->toBeNull();
});

it('removes a routed workspace and an unrouted workspace on cancel', function (string $slug, bool $routed, bool $attached): void {
    app(TaskExtensionState::class)->enable();
    bind_task_node_reachability();
    $project = routing_mode_project($slug, $routed);
    $node = routing_mode_node($slug.'-cancel');
    $group = routing_mode_group($project);
    routing_mode_bind_boundaries();
    $instance = app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for($group));
    expect($instance)->toBeInstanceOf(Instance::class);
    $ordinary = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/srv/orbit/apps/'.$slug.'/default',
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('e', 40),
        'status' => InstanceState::Active,
    ]);
    if ($attached) {
        $group->taskable()->associate($instance);
    }
    $group->status = TaskGroupStatus::Running;
    $group->save();
    app()->instance(InstanceRemover::class, new class implements InstanceRemover
    {
        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            expect($force)->toBeTrue();
            $instance->update(['status' => InstanceState::SourceResolved]);
            $routeIds = DB::table('route_targets')->where('instance_id', $instance->id)->pluck('route_id');
            DB::table('route_targets')->where('instance_id', $instance->id)->delete();
            DB::table('vite_port_assignments')->where('instance_id', $instance->id)->delete();
            Route::query()->whereIn('id', $routeIds)->delete();
            $instance->delete();

            return new InstanceRemoval;
        }
    });

    $cancelled = app(CancelTaskGroupAction::class)->execute($group->fresh() ?? $group);

    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->taskable_id)->toBeNull()
        ->and(Instance::query()->whereKey($instance->id)->exists())->toBeFalse()
        ->and(DB::table('route_targets')->where('instance_id', $instance->id)->exists())->toBeFalse()
        ->and(Instance::query()->whereKey($ordinary->id)->exists())->toBeTrue();
})->with([
    'attached orbit routed' => ['orbit', true, true],
    'attached shop unrouted' => ['shop', false, true],
    'unattached orbit unrouted' => ['orbit', false, false],
    'unattached shop routed' => ['shop', true, false],
]);

function routing_mode_project(
    string $slug,
    bool $routed,
    ProjectType $type = ProjectType::LaravelApp,
    ?string $root = 'public',
): Project {
    return Project::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
        'type' => $type,
        'default_branch' => 'main',
        'root' => $root,
        'task_workspace_routed' => $routed,
    ]);
}

function routing_mode_node(string $name): Node
{
    static $octet = 200;
    $octet++;
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => "{$name}.test",
        'public_ssh_host' => "10.44.1.{$octet}",
        'wireguard_ip' => "10.44.1.{$octet}",
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
        'runtime_config' => ['command' => ['/home/orbit/.local/bin/pi-server', 'serve', "--host=10.44.1.{$octet}", '--port=3774']],
        'restart_policy' => 'always',
        'keep_alive' => true,
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function routing_mode_group(Project $project): Task
{
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Route the workspace',
        'brief' => 'Route the workspace from the Project setting.',
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

function routing_mode_bind_boundaries(): void
{
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
            return new DevelopmentSourceResolution($instance->name, str_repeat('a', 40));
        }

        public function inspectResolved(Instance $instance): DevelopmentSourceResolution
        {
            return new DevelopmentSourceResolution((string) $instance->branch, (string) $instance->starting_commit);
        }
    });
    app()->instance(DevelopmentInstanceConfigurator::class, new class implements DevelopmentInstanceConfigurator
    {
        public function inspect(Instance $instance): DevelopmentSourceProfile
        {
            return new DevelopmentSourceProfile('8.5', false);
        }

        public function configureLaravelUrl(Instance $instance, string $url): void {}
    });
    app()->instance(DevelopmentRouteProjector::class, new class implements DevelopmentRouteProjector
    {
        public function converge(Instance $instance, Route $route): void {}
    });
    app()->forgetInstance(DevelopmentInstanceProvisioner::class);
    app()->forgetInstance(NativeDevelopmentInstanceProvisioner::class);
}

function routing_mode_healthy_inspector(): InstanceStateInspector
{
    return new class implements InstanceStateInspector
    {
        public function inspect(Instance $instance): InstanceInspectionData
        {
            return new InstanceInspectionData(true, true, true, true);
        }
    };
}

function routing_mode_context(Node $node): DoctorNodeContext
{
    return new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true));
}
