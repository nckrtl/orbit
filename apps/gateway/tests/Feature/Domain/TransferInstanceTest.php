<?php

declare(strict_types=1);

use App\Actions\Instances\TransferInstanceAction;
use App\Data\Instances\TransferInstanceData;
use App\Domain\AppDev\AgentationPortAllocator;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentImporter;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceEnvironmentValidator;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Schedules\ScheduleTargetUseGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\NativeInstanceTransferRuntime;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Support\Orb245Accounts;
use Tests\Support\Orb245DestinationGuard;
use Tests\Support\Orb245EnvironmentLock;
use Tests\Support\Orb245EnvironmentReader;
use Tests\Support\Orb245EnvironmentWriter;
use Tests\Support\Orb245Projection;
use Tests\Support\Orb245SourceLock;
use Tests\Support\Orb245SqliteSeeder;
use Tests\Support\Orb245TransferRuntime;
use Tests\Support\Orb245TransferSource;
use Tests\Support\Orb368RouterLock;

beforeEach(function (): void {
    $this->orbitApp = Project::query()->create([
        'name' => 'Transfer shop',
        'slug' => 'shop',
        'repository_url' => 'https://example.test/shop.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    [$this->sourceCluster, $this->sourceNode] = orb245_clustered_app_dev(
        'source',
        '10.44.45.10',
        'dev.orbit',
    );
    [$this->destinationCluster, $this->destinationNode] = orb245_clustered_app_dev(
        'destination',
        '10.44.45.11',
        'other.orbit',
    );
    $this->instance = orb245_instance($this->orbitApp, $this->sourceNode, 'web', 'checkout');
    $this->route = orb245_route($this->instance, 'web.shop.dev.orbit', RouteProvenance::Generated);
    $this->process = Process::query()->create([
        'owner_type' => Instance::MorphAlias,
        'owner_id' => $this->instance->id,
        'name' => 'queue',
        'runtime' => 'systemd',
        'working_directory' => $this->instance->checkout_path,
        'runtime_config' => ['command' => ['php', 'artisan', 'queue:work']],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
    $this->instance->environmentValues()->create([
        'env_key' => 'APP_KEY',
        'env_value' => 'base64:stored-app-key',
    ]);
    $this->instance->environmentValues()->create([
        'env_key' => 'APP_URL',
        'env_value' => 'https://{{instance.domain}}/{{instance.environment}}',
    ]);
    $this->accounts = new Orb245Accounts;
    $this->destinationGuard = new Orb245DestinationGuard;
    $this->sources = new Orb245TransferSource;
    $this->runtime = new Orb245TransferRuntime;
    $this->sqlite = new Orb245SqliteSeeder;
    $this->reader = new Orb245EnvironmentReader;
    $this->writer = new Orb245EnvironmentWriter;
    $this->projection = new Orb245Projection;
    $this->environmentLock = new Orb245EnvironmentLock;
    $this->routerLock = new Orb368RouterLock;
    $this->action = new TransferInstanceAction(
        $this->accounts,
        app(StorageRootResolver::class),
        app(NodeSettingsNormalizer::class),
        app(ManagedCheckoutOverlap::class),
        $this->destinationGuard,
        $this->environmentLock,
        new Orb245SourceLock,
        $this->sources,
        $this->runtime,
        $this->sqlite,
        new InstanceEnvironmentContextResolver,
        $this->reader,
        new InstanceEnvironmentImporter,
        new InstanceEnvironmentStore(
            new InstanceEnvironmentContextResolver,
            new InstanceEnvironmentValidator,
        ),
        new InstanceEnvironmentRenderer,
        $this->writer,
        new RouteStateResolver,
        $this->projection,
        $this->projection,
        app(DevelopmentProjectionOperationLock::class),
        $this->routerLock,
        app(ScheduleTargetUseGuard::class),
        app(ProcessAdmissionLock::class),
    );
    $this->data = new TransferInstanceData(
        nodeId: $this->destinationNode->id,
        name: null,
        sqliteSourcePath: null,
    );
});

it('keeps a source annotator reservation through failed activation or retirement and frees it only after retry retires Caddy', function (string $failure): void {
    $allocator = app(AgentationPortAllocator::class);
    $allocator->assign($this->instance, 'annotator_port');
    if ($failure === 'activation') {
        $this->runtime->onCall = function (string $operation): void {
            if ($operation === 'activate') {
                $this->runtime->onCall = null;
                throw new ResourceOperationException('test.activation_failed', 'Destination activation failed.', 409);
            }
        };
    } else {
        $this->projection->failRetirementOnce = true;
    }
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    expect($this->instance->refresh()->node_id)->toBe($this->destinationNode->id)
        ->and(DB::table('annotation_port_assignments')->where('instance_id', $this->instance->id)->where('node_id', $this->sourceNode->id)->value('port'))->toBe(4848);
    $other = orb245_instance($this->orbitApp, $this->sourceNode, 'new-source-user', 'checkout');
    expect($allocator->assign($other, 'annotator_port'))->toBe(4849);
    $this->action->execute($this->instance->refresh(), $this->data);
    expect(DB::table('annotation_port_assignments')->where('instance_id', $this->instance->id)->where('node_id', $this->sourceNode->id)->exists())->toBeFalse()
        ->and($allocator->nextAvailable($this->sourceNode->id, 0, 'annotator_port'))->toBe(4848);
})->with(['activation', 'retirement']);

it('reassigns the annotator port at destination and preserves its environment URL', function (): void {
    $this->instance->update(['annotator_port' => 4855]);
    $this->instance->environmentValues()->create(['env_key' => 'ANNOTATOR_URL', 'env_value' => 'https://{{instance.domain}}/__orbit/annotator/annotations']);
    $occupied = orb245_instance($this->orbitApp, $this->destinationNode, 'occupied', 'checkout');
    $occupied->update(['annotator_port' => 4848]);
    $result = $this->action->execute($this->instance, $this->data);
    expect($result['instance']->annotator_port)->toBe(4849)
        ->and($result['instance']->node_id)->toBe($this->destinationNode->id)
        ->and($this->writer->contents)->toContain('ANNOTATOR_URL="https://web.shop.other.orbit/__orbit/annotator/annotations"');
});

it('refuses transfer when schedules target the Instance', function (): void {
    Schedule::query()->create([
        'target_type' => Instance::MorphAlias,
        'target_id' => $this->instance->id,
        'host_node_id' => $this->sourceNode->id,
        'name' => 'nightly',
        'calendar' => '*-*-* 02:00:00',
        'command' => 'php artisan schedule:run',
        'timeout_seconds' => 60,
        'desired_timer_state' => DesiredTimerState::Enabled,
        'status' => LifecycleStatus::Active,
    ]);

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('schedule.target_in_use');
        });

    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id)
        ->and(InstanceTransfer::query()->exists())->toBeFalse()
        ->and($this->sources->calls)->toBeEmpty();
});

it('rechecks Schedules created after reserve before transfer cutover', function (): void {
    $this->runtime->onPause = function (Instance $instance): void {
        Schedule::query()->create([
            'target_type' => Instance::MorphAlias,
            'target_id' => $instance->id,
            'host_node_id' => $this->sourceNode->id,
            'name' => 'late-nightly',
            'calendar' => '*-*-* 02:00:00',
            'command' => 'php artisan schedule:run',
            'timeout_seconds' => 60,
            'desired_timer_state' => DesiredTimerState::Enabled,
            'status' => LifecycleStatus::Active,
        ]);
    };

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('schedule.target_in_use'));

    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id)
        ->and(InstanceTransfer::query()->whereNull('cutover_at')->exists())->toBeTrue();
});

it('transfers a development Instance to another app-dev Node in the same Cluster', function (): void {
    $this->destinationNode->update([
        'cluster_id' => $this->sourceCluster->id,
        'tld' => null,
    ]);
    $result = $this->action->execute($this->instance, $this->data);
    $instance = $result['instance'];
    $transfer = $result['transfer'];
    $route = $instance->authoritativeRoute();

    expect($result['created'])->toBeTrue()
        ->and($instance->id)->toBe($this->instance->id)
        ->and($instance->project_id)->toBe($this->orbitApp->id)
        ->and($instance->node_id)->toBe($this->destinationNode->id)
        ->and($instance->name)->toBe('web')
        ->and($instance->checkout_path)->toBe('/srv/orbit/apps/shop/web')
        ->and($instance->source_layout)->toBe('checkout')
        ->and($route?->id)->toBe($this->route->id)
        ->and($route?->domain)->toBe('web.shop.dev.orbit')
        ->and($route?->cluster_id)->toBe($this->sourceCluster->id)
        ->and($transfer->status)->toBe(InstanceTransferStatus::Completed)
        ->and($transfer->current_step)->toBe(InstanceTransferStep::Completed)
        ->and($transfer->cleanup_completed ?? $transfer->completed_at)->not->toBeNull()
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup'])
        ->and($this->runtime->calls)->toBe(['pause', 'relocate', 'activate', 'cleanup'])
        ->and($this->sqlite->calls)->toBeEmpty()
        ->and($this->projection->httpChecks)->toBe(0)
        ->and($this->process->refresh()->id)->toBe($this->process->id)
        ->and($this->process->desired_state)->toBe(DesiredProcessState::Running)
        ->and($this->process->working_directory)->toBe('/srv/orbit/apps/shop/web')
        ->and($this->writer->path)->toBe('/srv/orbit/apps/shop/web')
        ->and($this->writer->domain)->toBe('web.shop.dev.orbit')
        ->and($this->writer->contents)
        ->toBe("APP_KEY=\"base64:stored-app-key\"\nAPP_URL=\"https://web.shop.dev.orbit/development\"\nNEW_FROM_ENV=\"imported\"\n");
});

it('transfers a development Instance across Clusters and replaces a generated domain', function (): void {
    $this->destinationCluster->update(['tld' => 'other.orbit']);
    $this->destinationNode->update(['tld' => null]);
    $result = $this->action->execute($this->instance, $this->data);
    $instance = $result['instance'];
    $route = $instance->authoritativeRoute();

    expect($instance->node_id)->toBe($this->destinationNode->id)
        ->and($route?->id)->not->toBe($this->route->id)
        ->and($route?->domain)->toBe('web.shop.other.orbit')
        ->and($route?->cluster_id)->toBe($this->destinationCluster->id)
        ->and($route?->provenance)->toBe(RouteProvenance::Generated)
        ->and(Route::query()->whereKey($this->route->id)->exists())->toBeFalse();
});

it('keeps an explicit Route identity while moving its scope', function (): void {
    $this->instance->update([
        'status' => InstanceState::Reserved,
        'provisioning_step' => 'reserved',
    ]);
    $this->route->targets()->delete();
    $this->route->delete();
    $this->instance->refresh();
    $this->route = orb245_route($this->instance, 'shop.example.test', RouteProvenance::Explicit);
    $this->instance->update([
        'status' => InstanceState::Active,
        'provisioning_step' => 'active',
    ]);
    $this->destinationNode->update(['tld' => null]);

    $result = $this->action->execute($this->instance, $this->data);
    $route = $result['instance']->authoritativeRoute();

    expect($route?->id)->toBe($this->route->id)
        ->and($route?->domain)->toBe('shop.example.test')
        ->and($route?->cluster_id)->toBe($this->destinationCluster->id);
});

it('finalizes a generated Route replacement so environment access and immediate reverse transfer succeed', function (): void {
    $forward = $this->action->execute($this->instance, $this->data);
    $route = $forward['instance']->authoritativeRoute();

    expect($route->replacement_step)->toBeNull()
        ->and($route->replaces_route_id)->toBeNull()
        ->and($route->replaced_by_route_id)->toBeNull();
    $context = new InstanceEnvironmentContextResolver()->resolve($forward['instance'], true);
    expect($context->nodeId)->toBe($this->destinationNode->id)
        ->and($context->routeDomain)->toBe('web.shop.other.orbit');

    $reverse = $this->action->execute($forward['instance'], new TransferInstanceData(
        nodeId: $this->sourceNode->id,
        name: null,
        sqliteSourcePath: null,
    ));

    expect($reverse['instance']->id)->toBe($this->instance->id)
        ->and($reverse['instance']->node_id)->toBe($this->sourceNode->id)
        ->and($reverse['transfer']->status)->toBe(InstanceTransferStatus::Completed)
        ->and($reverse['instance']->routes)->toHaveCount(1);
    expect(new InstanceEnvironmentContextResolver()->resolve($reverse['instance'], true)->routeDomain)
        ->toBe('web.shop.dev.orbit');
});

it('owns the source Cluster Router before waiting for the environment lock shared with Cluster updates', function (): void {
    $this->environmentLock->beforeRun = function (): void {
        expect($this->routerLock->ownedClusterId)->toBe($this->sourceCluster->id);
    };

    $result = $this->action->execute($this->instance, $this->data);

    expect($result['transfer']->status)->toBe(InstanceTransferStatus::Completed)
        ->and($this->routerLock->ownedClusterId)->toBeNull();
});

it('returns a completed legacy transfer without requiring original Router evidence', function (): void {
    $completed = $this->action->execute($this->instance, $this->data);
    $completed['transfer']->update(['source_router_node_id' => null]);
    $calls = $this->sources->calls;

    $result = $this->action->execute($completed['instance'], $this->data);

    expect($result['created'])->toBeFalse()
        ->and($result['transfer']->id)->toBe($completed['transfer']->id)
        ->and($result['transfer']->status)->toBe(InstanceTransferStatus::Completed)
        ->and($this->sources->calls)->toBe($calls);
});

it('retains the source Route and Vite reservation until projection retirement can be retried', function (): void {
    $this->projection->failRetirementOnce = true;
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    $transfer = InstanceTransfer::query()->sole();
    $destination = Route::query()->findOrFail($transfer->destination_route_id);

    expect($this->route->refresh()->status)->toBe(RouteStatus::Retiring)
        ->and($destination->replacement_step)->toBe(RouteReplacementStep::DatabaseCutover)
        ->and($transfer->source_router_node_id)->toBe($this->sourceCluster->routerAssignment->node_id)
        ->and($transfer->completed_at)->toBeNull()
        ->and($this->sources->calls)->toBe(['capture', 'materialize'])
        ->and($this->runtime->calls)->toBe(['pause', 'relocate', 'activate']);
    expect(DB::table('vite_port_assignments')->where('instance_id', $this->instance->id)->count())->toBe(2);

    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['transfer']->status)->toBe(InstanceTransferStatus::Completed)
        ->and($this->projection->calls)->toBe(['converge', 'retire', 'retire'])
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup'])
        ->and($this->runtime->calls)->not->toContain('restore');
    $this->assertModelMissing($this->route);
});

it('refuses cleanup before remote deletion when the replacement ownership changed', function (): void {
    $this->projection->failRetirementOnce = true;
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    $transfer = InstanceTransfer::query()->sole();
    $destination = Route::query()->findOrFail($transfer->destination_route_id);
    $destination->update(['replaces_route_id' => null]);

    expect(fn () => $this->action->execute($this->instance->refresh(), $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_cleanup_conflict'));

    expect($this->projection->calls)->toBe(['converge', 'retire'])
        ->and($this->sources->calls)->toBe(['capture', 'materialize'])
        ->and($this->runtime->calls)->not->toContain('cleanup', 'restore')
        ->and($transfer->refresh()->completed_at)->toBeNull();
    $this->assertModelExists($this->route);
});

it('refuses ineligible sources and destinations before source mutation', function (
    Closure $mutate,
    string $code,
): void {
    $mutate($this);
    $instance = $this->instance->refresh();

    expect(fn () => $this->action->execute($instance, $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe($code));

    expect($this->sources->calls)->toBeEmpty()
        ->and($instance->refresh()->node_id)->toBe($this->sourceNode->id)
        ->and($instance->name)->toBe('web');
})->with([
    'production' => [function (object $test): void {
        $test->sourceNode->roles()->where('role', RoleName::AppDev->value)->delete();
        $test->sourceNode->roles()->create([
            'role' => RoleName::AppProd,
            'status' => LifecycleStatus::Active,
        ]);
    }, 'instance.production_refused'],
    'inactive instance' => [function (object $test): void {
        $test->instance->update(['status' => InstanceState::Reserved]);
    }, 'instance.lifecycle_conflict'],
    'same Node' => [function (object $test): void {
        $test->data = new TransferInstanceData($test->sourceNode->id, null, null);
    }, 'instance.same_node'],
    'inactive destination' => [function (object $test): void {
        $test->destinationNode->update(['status' => LifecycleStatus::Failed]);
    }, 'instance.node_inactive'],
    'destination without app-dev' => [function (object $test): void {
        $test->destinationNode->roles()->where('role', RoleName::AppDev)->delete();
    }, 'instance.node_not_app_dev'],
    'standalone destination' => [function (object $test): void {
        $test->destinationNode->update(['cluster_id' => null]);
    }, 'instance.standalone_unsupported'],
]);

it('refuses an occupied destination path with a rename hint before source mutation', function (): void {
    Instance::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->destinationNode->id,
        'name' => 'other',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/shop/web',
        'branch' => 'main',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.destination_exists')
                ->and($exception->getMessage())->toContain('destination already exists')
                ->and($exception->getMessage())->toContain('name');
        });

    expect($this->sources->calls)->toBeEmpty()
        ->and($this->instance->refresh()->name)->toBe('web');
});

it('recalculates destination path and generated domain for an explicit rename', function (): void {
    $data = new TransferInstanceData($this->destinationNode->id, 'preview', null);
    $result = $this->action->execute($this->instance, $data);
    $instance = $result['instance'];
    $route = $instance->authoritativeRoute();

    expect($instance->name)->toBe('preview')
        ->and($instance->checkout_path)->toBe('/srv/orbit/apps/shop/preview')
        ->and($route?->domain)->toBe('preview.shop.other.orbit');
});

it('rejects a colliding rename identity and leaves the original name unchanged', function (): void {
    Instance::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->destinationNode->id,
        'name' => 'preview',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/shop/preview',
        'branch' => 'main',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);

    expect(fn () => $this->action->execute(
        $this->instance,
        new TransferInstanceData($this->destinationNode->id, 'preview', null),
    ))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.identity_conflict'));

    expect($this->instance->refresh()->name)->toBe('web')
        ->and($this->sources->calls)->toBeEmpty();
});

it('resumes a SourceCaptured transfer after source process artifacts were removed', function (): void {
    $transfer = InstanceTransfer::query()->create([
        'instance_id' => $this->instance->id,
        'source_node_id' => $this->sourceNode->id,
        'source_router_node_id' => $this->sourceCluster->routerAssignment->node_id,
        'destination_node_id' => $this->destinationNode->id,
        'requested_name' => null,
        'destination_name' => 'web',
        'destination_path' => '/srv/orbit/apps/shop/web',
        'destination_domain' => 'web.shop.other.orbit',
        'sqlite_source_path' => null,
        'source_layout' => InstanceSourceLayout::Checkout,
        'source_path' => $this->instance->checkout_path,
        'common_repository_path' => null,
        'source_route_id' => $this->route->id,
        'status' => InstanceTransferStatus::InProgress,
        'current_step' => InstanceTransferStep::SourceCaptured,
    ]);
    $this->runtime->processArtifactsRemoved = true;

    $result = $this->action->execute($this->instance, $this->data);

    expect($result['created'])->toBeFalse()
        ->and($result['transfer']->id)->toBe($transfer->id)
        ->and($result['transfer']->status)->toBe(InstanceTransferStatus::Completed)
        ->and($this->runtime->processArtifactsRemoved)->toBeTrue()
        ->and($this->runtime->pauseOutcomes)->toBe(['already-removed'])
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup']);
});

it('pauses before capture', function (): void {
    $events = [];
    $this->runtime->onCall = function (string $call) use (&$events): void {
        $events[] = $call;
    };
    $this->sources->onCall = function (string $call) use (&$events): void {
        $events[] = $call;
    };

    $this->action->execute($this->instance, $this->data);

    expect(array_search('pause', $events, true))
        ->toBeLessThan(array_search('capture', $events, true));
});

it('gracefully stops an owned Docker Process before removing it and capturing the checkout', function (): void {
    $this->process->update(['runtime' => ProcessRuntime::Docker]);
    $this->schedule = Schedule::query()->create([
        'target_type' => Instance::MorphAlias,
        'target_id' => $this->instance->id,
        'host_node_id' => $this->sourceNode->id,
        'name' => 'nightly',
        'calendar' => '*-*-* 02:00:00',
        'command' => 'php artisan schedule:run',
        'timeout_seconds' => 60,
        'desired_timer_state' => DesiredTimerState::Enabled,
        'status' => LifecycleStatus::Active,
    ]);
    $events = [];
    $processes = Mockery::mock(ProcessRuntimeManager::class);
    $processes->shouldReceive('status')->once()->with(Mockery::on(
        fn (Process $process): bool => $process->is($this->process),
    ))->andReturnUsing(function () use (&$events): string {
        $events[] = 'status';

        return 'running';
    });
    $processes->shouldReceive('stop')->once()->with(Mockery::on(
        fn (Process $process): bool => $process->is($this->process),
    ))->andReturnUsing(function () use (&$events): void {
        $events[] = 'stop';
    });
    $processes->shouldReceive('remove')->once()->with(Mockery::on(
        fn (Process $process): bool => $process->is($this->process),
    ))->andReturnUsing(function () use (&$events): void {
        $events[] = 'remove';
    });

    $schedules = Mockery::mock(ScheduleRuntimeManager::class);
    $schedules->shouldReceive('remove')->once()->with(Mockery::on(
        fn (Schedule $schedule): bool => $schedule->is($this->schedule),
    ), true)->andReturnTrue();

    $this->sources->onCall = function (string $call) use (&$events): void {
        $events[] = $call;
    };
    (new NativeInstanceTransferRuntime($processes, $schedules))->pause($this->instance);
    $this->sources->capture($this->instance);

    expect($events)->toBe(['status', 'stop', 'remove', 'capture']);
});

it('repeats native transfer pause after process artifacts are removed', function (): void {
    $this->schedule = Schedule::query()->create([
        'target_type' => Instance::MorphAlias,
        'target_id' => $this->instance->id,
        'host_node_id' => $this->sourceNode->id,
        'name' => 'nightly',
        'calendar' => '*-*-* 02:00:00',
        'command' => 'php artisan schedule:run',
        'timeout_seconds' => 60,
        'desired_timer_state' => DesiredTimerState::Enabled,
        'status' => LifecycleStatus::Active,
    ]);
    $processes = Mockery::mock(ProcessRuntimeManager::class);
    $processes->shouldReceive('status')->twice()->andReturn('absent');
    $processes->shouldNotReceive('stop');
    $processes->shouldReceive('remove')->twice()->with(Mockery::on(
        fn (Process $process): bool => $process->is($this->process),
    ));

    $schedules = Mockery::mock(ScheduleRuntimeManager::class);
    $schedules->shouldReceive('remove')->twice()->with(Mockery::on(
        fn (Schedule $schedule): bool => $schedule->is($this->schedule),
    ), true)->andReturnTrue();

    $runtime = new NativeInstanceTransferRuntime($processes, $schedules);
    $runtime->pause($this->instance);
    $runtime->pause($this->instance);
});

it('captures source as an independent destination checkout without mutating source Git', function (): void {
    $this->action->execute($this->instance, $this->data);

    expect($this->sources->captures[0]->detached)->toBeTrue()
        ->and($this->sources->captures[0]->head)->toBe(str_repeat('a', 40))
        ->and($this->sources->captures[0]->refs)->toBe(['main', 'unpublished'])
        ->and($this->sources->mutatedSource)->toBeFalse()
        ->and($this->sources->materialized[0]->layout)->toBe(InstanceSourceLayout::Checkout)
        ->and($this->sources->materialized[0]->path)->toBe('/srv/orbit/apps/shop/web');
});

it('converts a source worktree into an independent destination checkout and preserves the common repository', function (): void {
    $this->instance->update([
        'source_layout' => InstanceSourceLayout::Worktree,
        'registration_common_repository_path' => '/home/orbit/.orbit/worktrees/shop.git',
    ]);
    $this->sources->layout = InstanceSourceLayout::Worktree;
    $this->sources->common = '/home/orbit/.orbit/worktrees/shop.git';

    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['instance']->source_layout)->toBe('checkout')
        ->and($this->sources->cleanupCommon)->toBe('/home/orbit/.orbit/worktrees/shop.git')
        ->and($this->sources->deletedCommon)->toBeFalse()
        ->and($this->sources->materialized[0]->layout)->toBe(InstanceSourceLayout::Checkout);
});

it('transfers only the selected SQLite snapshot after the source pause', function (): void {
    $data = new TransferInstanceData(
        $this->destinationNode->id,
        null,
        '/srv/orbit/apps/shop/web/database/database.sqlite',
    );
    $this->action->execute($this->instance, $data);

    expect($this->sqlite->calls)->toHaveCount(1)
        ->and($this->sqlite->sourcePath)->toBe('/srv/orbit/apps/shop/web/database/database.sqlite')
        ->and($this->sqlite->sourceBase)->toBe($this->instance->checkout_path)
        ->and($this->sqlite->destinationBase)->toBe('/srv/orbit/apps/shop/web')
        ->and($this->runtime->calls[0])->toBe('pause')
        ->and(array_search('pause', $this->runtime->calls, true))
        ->toBeLessThan(array_search('relocate', $this->runtime->calls, true));
});

it('refuses an unreadable env instead of silently transferring without it', function (): void {
    $this->reader->failure = new ResourceOperationException(
        'env.import_preflight_failed',
        'The recorded Instance environment file cannot be read safely.',
        409,
    );

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('env.import_preflight_failed'));

    expect($this->instance->environmentValues()->where('env_key', 'NEW_FROM_ENV')->exists())->toBeFalse()
        ->and(InstanceTransfer::query()->sole()->status)->toBe(InstanceTransferStatus::Failed);
});

it('imports no environment values when the source env is missing', function (): void {
    $this->reader->failure = new ResourceOperationException(
        'env.import_source_missing',
        'The recorded Instance environment file does not exist.',
        404,
    );

    $this->action->execute($this->instance, $this->data);

    expect($this->instance->environmentValues()->pluck('env_key')->all())->toBe(['APP_KEY', 'APP_URL']);
});

it('imports source .env without overwriting stored keys and rebuilds destination values', function (): void {
    $this->reader->contents = "APP_KEY=from-file\nNEW_FROM_ENV=imported\n";
    $this->action->execute($this->instance, $this->data);

    $stored = $this->instance->environmentValues()->pluck('env_value', 'env_key')->all();

    expect($stored['APP_KEY'])->toBe('base64:stored-app-key')
        ->and($stored['NEW_FROM_ENV'])->toBe('imported')
        ->and($this->writer->contents)->not->toContain('from-file')
        ->and(json_encode($this->writer->observed))->not->toContain('from-file');
});

it('restores the source and discards destination state when transfer fails before cutover', function (): void {
    $this->sources->failMaterialize = true;

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(ResourceOperationException::class);

    $transfer = InstanceTransfer::query()->where('instance_id', $this->instance->id)->sole();

    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id)
        ->and($this->instance->name)->toBe('web')
        ->and($this->runtime->calls)->toBe(['pause', 'restore'])
        ->and($this->sources->discarded)->toBe(['/srv/orbit/apps/shop/web'])
        ->and($transfer->cutover_at)->toBeNull()
        ->and($transfer->current_step)->toBe(InstanceTransferStep::Reserved)
        ->and($transfer->status)->toBe(InstanceTransferStatus::Failed);

    expect(DB::table('vite_port_assignments')->where('instance_id', $this->instance->id)->pluck('node_id')->all())->toBe([$this->sourceNode->id]);
    $this->sources->failMaterialize = false;
    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['created'])->toBeTrue()
        ->and($result['transfer']->id)->not->toBe($transfer->id)
        ->and($result['transfer']->status)->toBe(InstanceTransferStatus::Completed)
        ->and($result['instance']->node_id)->toBe($this->destinationNode->id);
});

it('persists open rollback intent and the original failure before abandoning SQLite or restoring source runtime', function (): void {
    $this->reader->failure = new ResourceOperationException('env.import_preflight_failed', 'Injected environment failure.', 409);
    $observed = [];
    $inspect = static function (string $event) use (&$observed): void {
        $transfer = InstanceTransfer::query()->sole();
        $observed[] = [
            $event,
            $transfer->status->value,
            $transfer->current_step->value,
            $transfer->failed_step?->value,
            $transfer->error_code,
            $transfer->recovery_evidence['rollback_pending'] ?? false,
            InstanceTransfer::query()->closed()->whereKey($transfer->id)->exists(),
        ];
    };
    $this->sqlite->onAbandon = static fn () => $inspect('abandon');
    $this->runtime->onRestore = static fn () => $inspect('restore');

    expect(fn () => $this->action->execute($this->instance, new TransferInstanceData(
        $this->destinationNode->id, null, $this->instance->checkout_path.'/database.sqlite',
    )))->toThrow(ResourceOperationException::class);

    expect($observed)->toBe([
        ['abandon', 'failed', 'sqlite-transferred', 'sqlite-transferred', 'env.import_preflight_failed', true, false],
        ['restore', 'failed', 'sqlite-transferred', 'sqlite-transferred', 'env.import_preflight_failed', true, false],
    ]);
    $transfer = InstanceTransfer::query()->sole();
    expect($transfer->current_step)->toBe(InstanceTransferStep::Reserved)
        ->and($transfer->recovery_evidence)->toBeNull()
        ->and(InstanceTransfer::query()->closed()->whereKey($transfer->id)->exists())->toBeTrue();
});

it('finishes pending rollback when the replacement Route was deleted before its transfer reference was cleared', function (): void {
    $schedule = null;
    $interrupted = null;
    $sourceRouteId = $this->route->id;
    $this->sources->onCall = function (string $event) use (&$schedule): void {
        if ($event === 'capture' && $schedule === null) {
            $schedule = Schedule::query()->create([
                'target_type' => Instance::MorphAlias,
                'target_id' => $this->instance->id,
                'host_node_id' => $this->sourceNode->id,
                'name' => 'interruption-fixture',
                'calendar' => '*-*-* 02:00:00',
                'command' => 'php artisan schedule:run',
                'timeout_seconds' => 60,
                'desired_timer_state' => DesiredTimerState::Enabled,
                'status' => LifecycleStatus::Active,
            ]);
        }
    };
    Route::deleted(static function (Route $route) use ($sourceRouteId, &$interrupted): void {
        if ($route->replaces_route_id === $sourceRouteId) {
            $interrupted = InstanceTransfer::query()->sole()->getAttributes();
        }
    });

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('schedule.target_in_use'));
    expect($interrupted)->toBeArray()
        ->and($interrupted['current_step'])->toBe(InstanceTransferStep::RoutePrepared->value)
        ->and(Route::query()->whereKey($interrupted['destination_route_id'])->exists())->toBeFalse();
    $schedule->delete();
    DB::table('instance_transfers')->where('id', $interrupted['id'])->update($interrupted);

    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['created'])->toBeFalse()
        ->and($result['transfer']->id)->toBe($interrupted['id'])
        ->and($result['transfer']->status)->toBe(InstanceTransferStatus::Completed)
        ->and($result['transfer']->destination_route_id)->not->toBe($interrupted['destination_route_id'])
        ->and(Route::query()->whereKey($result['transfer']->destination_route_id)->exists())->toBeTrue()
        ->and($result['instance']->node_id)->toBe($this->destinationNode->id);
});

it('requires an identical retry for incomplete rollback then permits new input once rollback finishes', function (): void {
    $this->sources->failMaterialize = true;
    $this->sources->failDiscard = true;
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    $transfer = InstanceTransfer::query()->sole();
    expect($transfer->recovery_evidence['incomplete'])->toBe(['destination-checkout']);
    expect(fn () => $this->action->execute(
        $this->instance->refresh(),
        new TransferInstanceData($this->destinationNode->id, 'other', null),
    ))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_retry_conflict'));

    $this->sources->failDiscard = false;
    expect(fn () => $this->action->execute($this->instance->refresh(), $this->data))->toThrow(ResourceOperationException::class);
    expect($transfer->refresh()->recovery_evidence)->toBeNull()
        ->and(InstanceTransfer::query()->count())->toBe(1);
    $this->sources->failMaterialize = false;
    $result = $this->action->execute($this->instance->refresh(), new TransferInstanceData($this->destinationNode->id, 'other', null));
    expect($result['created'])->toBeTrue()
        ->and($result['transfer']->id)->not->toBe($transfer->id)
        ->and($result['instance']->name)->toBe('other');
});

it('rolls back imported env when route preparation fails before cutover', function (): void {
    $this->reader->contents = "APP_KEY=from-file\nNEW_FROM_ENV=imported\n";
    Route::creating(static function (Route $route): void {
        if ($route->status === RouteStatus::Pending) {
            throw new ResourceOperationException('route.prepare_failed', 'Route preparation failed.', 409);
        }
    });

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(ResourceOperationException::class);

    expect($this->instance->environmentValues()->pluck('env_key')->all())->toBe(['APP_KEY', 'APP_URL'])
        ->and(InstanceTransfer::query()->sole()->imported_environment_keys)->toBe([]);
});

it('rolls back previously imported env keys after retry adds new imports', function (): void {
    Route::creating(static function (Route $route): void {
        if ($route->status === RouteStatus::Pending) {
            throw new ResourceOperationException('route.prepare_failed', 'Route preparation failed.', 409);
        }
    });
    $this->reader->contents = "IMPORTED_A=one\n";

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(ResourceOperationException::class);

    $transfer = InstanceTransfer::query()->sole();
    expect($this->instance->environmentValues()->where('env_key', 'IMPORTED_A')->exists())->toBeFalse();

    // Simulate A surviving an incomplete rollback; the transfer must retain ownership of it.
    $this->instance->environmentValues()->create([
        'env_key' => 'IMPORTED_A',
        'env_value' => 'one',
    ]);
    $transfer->update(['imported_environment_keys' => ['IMPORTED_A']]);
    $this->reader->contents = "IMPORTED_B=two\n";

    expect(fn () => $this->action->execute($this->instance->refresh(), $this->data))
        ->toThrow(ResourceOperationException::class);

    expect($this->instance->environmentValues()->pluck('env_key')->all())->toBe(['APP_KEY', 'APP_URL'])
        ->and($transfer->refresh()->imported_environment_keys)->toBe([]);
});

it('refuses a pre-cutover retry when schedules target the Instance', function (): void {
    $this->sources->failMaterialize = true;

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(ResourceOperationException::class);

    $transfer = InstanceTransfer::query()->where('instance_id', $this->instance->id)->sole();
    $sourceCalls = $this->sources->calls;
    $runtimeCalls = $this->runtime->calls;

    Schedule::query()->create([
        'target_type' => Instance::MorphAlias,
        'target_id' => $this->instance->id,
        'host_node_id' => $this->sourceNode->id,
        'name' => 'nightly',
        'calendar' => '*-*-* 02:00:00',
        'command' => 'php artisan schedule:run',
        'timeout_seconds' => 60,
        'desired_timer_state' => DesiredTimerState::Enabled,
        'status' => LifecycleStatus::Active,
    ]);
    $this->sources->failMaterialize = false;

    expect(fn () => $this->action->execute($this->instance->refresh(), $this->data))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('schedule.target_in_use');
        });

    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id)
        ->and($transfer->refresh()->cutover_at)->toBeNull()
        ->and($transfer->refresh()->current_step)->toBe(InstanceTransferStep::Reserved)
        ->and($this->sources->calls)->toBe($sourceCalls)
        ->and($this->runtime->calls)->toBe($runtimeCalls);
});

it('continues only forward after cutover and does not recopy source', function (): void {
    $this->projection->failOnce = true;

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(ResourceOperationException::class);

    $transfer = InstanceTransfer::query()->where('instance_id', $this->instance->id)->sole();

    expect($this->instance->refresh()->node_id)->toBe($this->destinationNode->id)
        ->and($transfer->cutover_at)->not->toBeNull()
        ->and($this->runtime->calls)->not->toContain('restore')
        ->and($this->sources->calls)->toBe(['capture', 'materialize']);

    expect(fn () => $this->action->execute(
        $this->instance->refresh(),
        new TransferInstanceData($this->destinationNode->id, 'other', null),
    ))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_retry_conflict'));

    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['created'])->toBeFalse()
        ->and($result['transfer']->status)->toBe(InstanceTransferStatus::Completed)
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup'])
        ->and($this->runtime->calls)->not->toContain('restore');
});

it('reports incomplete old-placement cleanup and retries only cleanup', function (): void {
    $this->sources->cleanupIncomplete = true;

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_cleanup_incomplete'));

    $transfer = InstanceTransfer::query()->where('instance_id', $this->instance->id)->sole();

    expect($this->instance->refresh()->node_id)->toBe($this->destinationNode->id)
        ->and($transfer->recovery_evidence)->toHaveKey('incomplete')
        ->and($this->route->refresh()->status)->toBe(RouteStatus::Retiring)
        ->and(Route::query()->findOrFail($transfer->destination_route_id)->replacement_step)->toBe(RouteReplacementStep::DatabaseCutover)
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup']);

    expect(DB::table('vite_port_assignments')->where('instance_id', $this->instance->id)->count())->toBe(2);
    $this->sources->cleanupIncomplete = false;
    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['transfer']->status)->toBe(InstanceTransferStatus::Completed)
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup', 'cleanup']);
    expect(DB::table('vite_port_assignments')->where('instance_id', $this->instance->id)->pluck('node_id')->all())->toBe([$this->destinationNode->id]);
});

it('completes transfer from verified placement state without application HTTP health', function (): void {
    $this->action->execute($this->instance, $this->data);

    expect($this->projection->httpChecks)->toBe(0)
        ->and($this->projection->calls)->toBe(['converge', 'retire']);
});

/**
 * @return array{Cluster, Node}
 */
function orb245_clustered_app_dev(string $role, string $address, string $tld): array
{
    $cluster = Cluster::query()->create([
        'name' => "transfer-{$role}",
        'tld' => $tld,
        'state' => ClusterState::Active,
    ]);
    $node = Node::query()->create([
        'name' => "transfer-{$role}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => $tld,
        'cluster_id' => $cluster->id,
        'public_ssh_host' => "{$role}.example.test",
        'wireguard_ip' => $address,
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $node->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $router = Node::query()->create([
        'name' => "transfer-{$role}-router",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'cluster_id' => $cluster->id,
        'public_ssh_host' => "{$role}-router.example.test",
        'wireguard_ip' => preg_replace('/\.\d+$/', '.2'.substr($address, -1), $address) ?: $address,
        'user' => 'orbit',
    ]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);

    return [$cluster, $node];
}

function orb245_instance(Project $project, Node $node, string $name, string $layout): Instance
{
    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => 'development',
        'source_layout' => $layout,
        'checkout_path' => "/srv/orbit/apps/{$project->slug}/{$name}",
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
}

function orb245_route(Instance $instance, string $domain, RouteProvenance $provenance): Route
{
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'cluster_id' => $instance->node->cluster_id,
        'generation_basis_node_id' => $provenance === RouteProvenance::Generated ? $instance->node_id : null,
        'domain' => $domain,
        'provenance' => $provenance,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}
