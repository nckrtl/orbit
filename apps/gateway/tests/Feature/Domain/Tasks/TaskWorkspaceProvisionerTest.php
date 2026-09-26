<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\AppInstances\AppInstanceRemover;
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
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\ProjectNodeExclusion;
use App\Models\Task;
use App\Models\TaskGroup;

function provisioner_app(
    string $slug,
    ?string $root = 'public',
    ProjectType $type = ProjectType::LaravelApp,
): OrbitApp {
    return OrbitApp::query()->create([
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
        'status' => TaskStatus::Todo,
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

it('creates a visitable Task workspace at the repository root for each package type', function (ProjectType $type): void {
    $app = provisioner_app($type->value, '.', $type);
    $node = provisioner_node($type->value.'-dev', '10.44.0.125');
    $group = provisioner_group($app);
    $fakes = bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, true));

    expect($instance)->toBeInstanceOf(AppInstance::class)
        ->and($instance?->node_id)->toBe($node->id)
        ->and($instance?->root)->toBe('.')
        ->and($instance?->status)->toBe(AppInstanceState::SourceResolved)
        ->and($fakes->development->reserves)->toBe(1)
        ->and($fakes->development->completes)->toBe(1);
})->with([
    'laravel-package' => ProjectType::LaravelPackage,
    'node-package' => ProjectType::NodePackage,
]);

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

it('reports a capacity wait without creating a workspace when every capable Node is full', function (bool $otherNodeHasRoom): void {
    $app = provisioner_app('full');
    $incapable = provisioner_node('no-t3', '10.44.0.110');
    $incapable->processes()->delete();
    $capable = provisioner_node('full-t3', '10.44.0.111');
    foreach ($otherNodeHasRoom ? [$capable] : [$capable, $incapable] as $node) {
        $occupied = AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => "occupied-{$node->id}",
            'checkout_path' => "/srv/orbit/apps/full/occupied-{$node->id}",
            'status' => AppInstanceState::SourceResolved,
        ]);
        for ($i = 0; $i < TaskCeilings::PerNode; $i++) {
            $active = provisioner_group($app, "Active {$node->id} {$i}");
            $active->taskable()->associate($occupied);
            $active->save();
        }
    }
    $group = provisioner_group($app);
    $fakes = bind_task_workspace_fakes();

    expect(fn () => app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false)))
        ->toThrow(fn (TaskCapacityException $exception) => expect($exception->fleetFull)->toBe(! $otherNodeHasRoom))
        ->and($fakes->source->calls)->toBe([]);
    $this->assertDatabaseCount('app_instances', $otherNodeHasRoom ? 1 : 2);
})->with([
    'another app-dev Node has room' => [true],
    'the whole fleet is full' => [false],
]);

it('places a group only on a Node that allows both its implementer and reviewer drivers', function (): void {
    $app = provisioner_app('mixed');
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
    $group = provisioner_group($app);
    $group->update(['implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 't3']);
    bind_task_workspace_fakes();

    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group->fresh() ?? $group, false));

    expect($instance?->node_id)->toBe($both->id);
    $this->assertDatabaseMissing('app_instances', ['node_id' => $t3Only->id]);
});

it('returns null when no Node allows the implementer driver', function (): void {
    $app = provisioner_app('no-pi');
    provisioner_node('t3-only', '10.44.0.115');
    $group = provisioner_group($app);
    $group->update(['implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 't3']);
    $fakes = bind_task_workspace_fakes();

    expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group->fresh() ?? $group, false)))->toBeNull()
        ->and($fakes->source->calls)->toBe([]);
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

it('places a planning group only on an app-dev Node with access to itself', function (): void {
    $app = provisioner_app('planner');
    $withoutAccess = provisioner_node('sabre', '10.44.0.130');
    $selfAccess = provisioner_node('shark', '10.44.0.131');
    $selfAccess->accessibleNodes()->attach($selfAccess->id);
    bind_task_workspace_fakes();

    $planning = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent(provisioner_group($app, 'Planning'), true, selfAccess: true));
    $managed = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent(provisioner_group($app, 'Managed'), true));

    expect($planning?->node_id)->toBe($selfAccess->id)
        ->and($managed?->node_id)->toBe($withoutAccess->id);
});

it('accepts a Node with access to the Gateway for a planning group', function (): void {
    $app = provisioner_app('planner-gateway');
    provisioner_node('sabre', '10.44.0.130');
    $withGatewayAccess = provisioner_node('beast', '10.44.0.131');
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2',
    ]));
    $withGatewayAccess->accessibleNodes()->attach($gateway->id);
    bind_task_workspace_fakes();

    $planning = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent(provisioner_group($app), true, selfAccess: true));

    expect($planning?->node_id)->toBe($withGatewayAccess->id);
});

it('returns null for a planning group when no app-dev Node has access to itself', function (): void {
    $app = provisioner_app('planner-none');
    provisioner_node('sabre', '10.44.0.130');
    bind_task_workspace_fakes();

    expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent(provisioner_group($app), true, selfAccess: true)))->toBeNull();
    $this->assertDatabaseCount('app_instances', 0);
});

describe('a workspace an interrupted claim left unattached', function (): void {
    it('resumes it on its own Node instead of the least loaded one', function (): void {
        $app = provisioner_app('orbit');
        provisioner_node('first', '10.44.0.130');
        $second = provisioner_node('second', '10.44.0.131');
        $group = provisioner_group($app);
        $left = AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $second->id,
            'name' => TaskWorkspaceName::for($group),
            'checkout_path' => '/srv/orbit/apps/orbit/'.TaskWorkspaceName::for($group),
            'branch_override' => TaskWorkspaceName::for($group),
            'status' => AppInstanceState::CheckoutPrepared,
        ]);
        bind_task_workspace_fakes();

        $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));

        expect($instance?->id)->toBe($left->id)
            ->and($instance?->status)->toBe(AppInstanceState::SourceResolved);
        $this->assertDatabaseCount('app_instances', 1);
    });

    it('waits for capacity on its own Node', function (): void {
        $app = provisioner_app('orbit');
        provisioner_node('roomy', '10.44.0.132');
        $full = provisioner_node('full', '10.44.0.133');
        $occupied = AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $full->id,
            'name' => 'occupied',
            'checkout_path' => '/srv/orbit/apps/orbit/occupied',
            'status' => AppInstanceState::SourceResolved,
        ]);
        for ($i = 0; $i < TaskCeilings::PerNode; $i++) {
            $active = provisioner_group($app, "Active {$i}");
            $active->taskable()->associate($occupied);
            $active->save();
        }
        $group = provisioner_group($app);
        AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $full->id,
            'name' => TaskWorkspaceName::for($group),
            'checkout_path' => '/srv/orbit/apps/orbit/'.TaskWorkspaceName::for($group),
            'branch_override' => TaskWorkspaceName::for($group),
            'status' => AppInstanceState::Reserved,
        ]);
        bind_task_workspace_fakes();

        expect(fn () => app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false)))
            ->toThrow(fn (TaskCapacityException $exception) => expect($exception->fleetFull)->toBeFalse());
    });

    it('never adopts an Instance that only shares the workspace name', function (): void {
        $app = provisioner_app('orbit');
        $node = provisioner_node('only', '10.44.0.134');
        $group = provisioner_group($app);
        $lookalike = AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => TaskWorkspaceName::for($group),
            'checkout_path' => '/srv/orbit/apps/orbit/'.TaskWorkspaceName::for($group),
            'branch_override' => 'feature-x',
            'status' => AppInstanceState::SourceResolved,
        ]);
        bind_task_workspace_fakes();

        expect(app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false)))->toBeNull()
            ->and($lookalike->fresh()?->branch_override)->toBe('feature-x')
            ->and($lookalike->fresh()?->status)->toBe(AppInstanceState::SourceResolved);
        $this->assertDatabaseCount('app_instances', 1);
    });
});

/**
 * @return array{0: TaskGroup, 1: AppInstance, 2: string}
 */
function orbit_workspace_clone(): array
{
    $app = provisioner_app('orbit', type: ProjectType::Monorepo);
    provisioner_node('orbit-clone', '10.44.0.181');
    $group = provisioner_group($app, 'Clone');
    bind_task_workspace_fakes();
    $instance = app(TaskWorkspaceProvisioner::class)->provision(new InstanceProvisionIntent($group, false));
    expect($instance)->toBeInstanceOf(AppInstance::class)
        ->and($instance->status)->toBe(AppInstanceState::SourceResolved)
        ->and($instance->routes()->count())->toBe(0);

    $checkout = sys_get_temp_dir().'/orbit-task-'.$instance->id;
    if (! is_dir($checkout)) {
        mkdir($checkout);
    }
    file_put_contents($checkout.'/KEEP', 'clone');
    $instance->update(['checkout_path' => $checkout]);

    return [$group->fresh(['app', 'taskable']) ?? $group, $instance->fresh() ?? $instance, $checkout];
}

function bind_checkout_remover(): void
{
    app()->instance(AppInstanceRemover::class, new class implements AppInstanceRemover
    {
        public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
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

            return new AppInstanceRemoval;
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
            ->and(AppInstance::query()->whereKey($instance->id)->exists())->toBeFalse();
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
    app()->instance(AppInstanceRemover::class, new class implements AppInstanceRemover
    {
        public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
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
                ->and(AppInstance::query()->whereKey($instance->id)->exists())->toBeTrue();

            return;
        }

        expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
            ->toThrow(ResourceOperationException::class, 'The checkout could not be inspected.');

        $fresh = $group->fresh();
        expect(is_dir($checkout))->toBeTrue()
            ->and(file_get_contents($checkout.'/KEEP'))->toBe('clone')
            ->and(AppInstance::query()->whereKey($instance->id)->exists())->toBeTrue()
            ->and($fresh?->taskable_id)->toBe($operation === 'unattached' ? null : $instance->id)
            ->and($fresh?->assistance_requested)->toBeTrue()
            ->and($fresh?->assistance_reason)->toBe(RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The checkout could not be inspected.')
            ->and($fresh?->status)->toBe(TaskGroupStatus::Running);
    } finally {
        forget_checkout($checkout);
    }
})->with(['cancel', 'complete', 'unattached']);
