<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceProvisioner;
use App\Domain\AppInstances\DevelopmentAppInstanceSourceLifecycle;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCeilings;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\ProjectNodeExclusion;
use App\Models\Task;
use App\Models\TaskGroup;

function provisioner_app(string $slug, ?string $root = 'public'): OrbitApp
{
    return OrbitApp::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
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

function provisioner_group(OrbitApp $app, string $title = 'Workspace'): TaskGroup
{
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => $title,
        'brief' => "{$title} brief",
        'status' => TaskGroupStatus::Reserved,
    ]);
    Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'First',
        'brief' => 'First subtask',
        'status' => TaskStatus::Pending,
    ]);

    return $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
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
    $destination = new class implements AppInstanceDestinationGuard
    {
        public function assertUnoccupied(Node $node, StoragePath $destination): void {}
    };
    $source = new class implements DevelopmentAppInstanceSourceLifecycle
    {
        /** @var list<string> */
        public array $calls = [];

        public function prepare(AppInstance $appInstance, bool $allowExisting): void
        {
            $this->calls[] = 'prepare';
        }

        public function inspectPrepared(AppInstance $appInstance): void
        {
            $this->calls[] = 'inspect-prepared';
        }

        public function resolve(AppInstance $appInstance): DevelopmentSourceResolution
        {
            $this->calls[] = 'resolve';

            return new DevelopmentSourceResolution($appInstance->name, str_repeat('a', 40));
        }

        public function inspectResolved(AppInstance $appInstance): DevelopmentSourceResolution
        {
            $this->calls[] = 'inspect-resolved';

            return new DevelopmentSourceResolution((string) $appInstance->branch, (string) $appInstance->starting_commit);
        }
    };
    $development = new class implements DevelopmentAppInstanceProvisioner
    {
        public int $reserves = 0;

        public int $completes = 0;

        public function reserve(AppInstance $appInstance, ?string $domain): void
        {
            $this->reserves++;
        }

        public function complete(
            AppInstance $appInstance,
            ?string $domain,
            bool $recoverSourceProfile = false,
        ): AppInstance {
            $this->completes++;

            return $appInstance->refresh();
        }
    };

    app()->instance(ManagedUserAccountResolver::class, $accounts);
    app()->instance(AppInstanceDestinationGuard::class, $destination);
    app()->instance(DevelopmentAppInstanceSourceLifecycle::class, $source);
    app()->instance(DevelopmentAppInstanceProvisioner::class, $development);

    return (object) ['source' => $source, 'development' => $development];
}

it('leaves a group reserved when no app-dev Node can take the workspace', function (): void {
    $app = provisioner_app('lonely');
    $group = provisioner_group($app);
    bind_task_workspace_fakes();

    expect(app(InstanceProvisioning::class)->provision(new InstanceProvisionIntent($group, true)))
        ->toBeNull();
});

it('creates a non-visitable Orbit checkout without activating a Route', function (): void {
    $app = provisioner_app('orbit');
    $node = provisioner_node('orbit-dev', '10.44.0.101');
    $group = provisioner_group($app, 'Isolated');
    $fakes = bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance)->toBeInstanceOf(AppInstance::class)
        ->and($instance?->node_id)->toBe($node->id)
        ->and($instance?->name)->toBe(TaskWorkspaceName::for($group))
        ->and($instance?->checkout_path)->toBe('/srv/orbit/apps/orbit/'.TaskWorkspaceName::for($group))
        ->and($instance?->branch_override)->toBe(TaskWorkspaceName::for($group))
        ->and($instance?->branch)->toBe(TaskWorkspaceName::for($group))
        ->and($instance?->status)->toBe(AppInstanceState::SourceResolved)
        ->and($instance?->routes()->count())->toBe(0)
        ->and($fakes->source->calls)->toBe(['prepare', 'inspect-prepared', 'resolve', 'inspect-prepared', 'inspect-resolved'])
        ->and($fakes->development->reserves)->toBe(0)
        ->and($fakes->development->completes)->toBe(0);
});

it('activates a visitable workspace through the development provisioner', function (): void {
    $app = provisioner_app('shop');
    $node = provisioner_node('shop-dev', '10.44.0.102');
    $group = provisioner_group($app, 'Inspect');
    $fakes = bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

    expect($instance?->node_id)->toBe($node->id)
        ->and($instance?->root)->toBe('public')
        ->and($instance?->status)->toBe(AppInstanceState::SourceResolved)
        ->and($fakes->development->reserves)->toBe(1)
        ->and($fakes->development->completes)->toBe(1);
});

it('reuses an already assigned Task workspace', function (): void {
    $app = provisioner_app('reuse');
    $node = provisioner_node('reuse-dev', '10.44.0.103');
    $group = provisioner_group($app);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'existing',
        'checkout_path' => '/srv/orbit/apps/reuse/existing',
        'status' => AppInstanceState::SourceResolved,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    bind_task_workspace_fakes();

    expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group->fresh(['taskable']) ?? $group, true))?->id)
        ->toBe($instance->id);
});

it('returns null when a visitable App lacks a web root', function (): void {
    $app = provisioner_app('bare', null);
    provisioner_node('bare-dev', '10.44.0.104');
    $group = provisioner_group($app);
    bind_task_workspace_fakes();

    expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true)))
        ->toBeNull();
});

it('returns null when destination occupation refuses the checkout', function (): void {
    $app = provisioner_app('blocked');
    provisioner_node('blocked-dev', '10.44.0.105');
    $group = provisioner_group($app);
    bind_task_workspace_fakes();
    app()->instance(AppInstanceDestinationGuard::class, new class implements AppInstanceDestinationGuard
    {
        public function assertUnoccupied(Node $node, StoragePath $destination): void
        {
            throw new ResourceOperationException(
                'instance.migration_conflict',
                'AppInstance destination is occupied by unmanaged data.',
                409,
            );
        }
    });

    expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false)))
        ->toBeNull();
});

it('skips an excluded app-dev Node before choosing the least loaded node', function (): void {
    $app = provisioner_app('orbit');
    $excluded = provisioner_node('sabre', '10.44.0.120');
    $allowed = provisioner_node('shark', '10.44.0.121');
    ProjectNodeExclusion::query()->create(['app_id' => $app->id, 'node_id' => $excluded->id]);
    $group = provisioner_group($app);
    bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance?->node_id)->toBe($allowed->id);
});

it('skips a non-T3 app-dev Node even when it has the lower id', function (): void {
    $app = provisioner_app('placement');
    $incapable = provisioner_node('no-t3', '10.44.0.110');
    $incapable->processes()->delete();
    $capable = provisioner_node('with-t3', '10.44.0.111');
    $group = provisioner_group($app);
    bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance?->node_id)->toBe($capable->id);
    $this->assertDatabaseMissing('app_instances', ['node_id' => $incapable->id]);
});

it('returns null without creating a workspace when the only capable Node is full', function (): void {
    $app = provisioner_app('full');
    $incapable = provisioner_node('no-t3', '10.44.0.110');
    $incapable->processes()->delete();
    $capable = provisioner_node('full-t3', '10.44.0.111');
    $occupied = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $capable->id,
        'name' => 'occupied',
        'checkout_path' => '/srv/orbit/apps/full/occupied',
        'status' => AppInstanceState::SourceResolved,
    ]);
    for ($i = 0; $i < TaskCeilings::PerNode; $i++) {
        $active = provisioner_group($app, "Active {$i}");
        $active->taskable()->associate($occupied);
        $active->save();
    }
    $group = provisioner_group($app);
    $fakes = bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance)->toBeNull()
        ->and($fakes->source->calls)->toBe([]);
    $this->assertDatabaseCount('app_instances', 1);
    $this->assertDatabaseMissing('app_instances', ['node_id' => $incapable->id]);
});

it('returns null when the app-dev Node has no usable T3 process', function (string $reason): void {
    $app = provisioner_app('unavailable');
    $node = provisioner_node('unavailable', '10.44.0.112');
    match ($reason) {
        'missing' => $node->processes()->delete(),
        'unrelated' => $node->processes()->update(['name' => 'other-service']),
        'failed' => $node->processes()->update(['status' => LifecycleStatus::Failed]),
        'stopped' => $node->processes()->update(['desired_state' => DesiredProcessState::Stopped]),
        'no-address' => $node->update(['wireguard_ip' => null]),
        'empty-address' => $node->update(['wireguard_ip' => '']),
    };
    $group = provisioner_group($app);
    $fakes = bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

    expect($instance)->toBeNull()
        ->and($fakes->source->calls)->toBe([]);
    $this->assertDatabaseCount('app_instances', 0);
})->with(['missing', 'unrelated', 'failed', 'stopped', 'no-address', 'empty-address']);
