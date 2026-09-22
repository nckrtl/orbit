<?php

declare(strict_types=1);

use App\Actions\AppInstances\TransferAppInstanceAction;
use App\Data\AppInstances\TransferAppInstanceData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentImporter;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRenderer;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentStore;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentValidator;
use App\Domain\AppInstances\Transfer\AppInstanceTransferStatus;
use App\Domain\AppInstances\Transfer\AppInstanceTransferStep;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppInstances\RemoteAppInstanceEnvironmentAccess;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceTransfer;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use App\Models\Schedule;
use Illuminate\Database\QueryException;
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
    $this->orbitApp = OrbitApp::query()->create([
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
        'owner_type' => AppInstance::class,
        'owner_id' => $this->instance->id,
        'name' => 'queue',
        'runtime' => 'systemd',
        'working_directory' => $this->instance->checkout_path,
        'runtime_config' => ['command' => ['php', 'artisan', 'queue:work']],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
    $this->schedule = Schedule::query()->create([
        'target_type' => AppInstance::class,
        'target_id' => $this->instance->id,
        'host_node_id' => $this->sourceNode->id,
        'name' => 'nightly',
        'calendar' => '*-*-* 02:00:00',
        'command' => 'php artisan schedule:run',
        'timeout_seconds' => 60,
        'desired_timer_state' => DesiredTimerState::Enabled,
        'status' => LifecycleStatus::Active,
    ]);
    $this->instance->environmentValues()->create([
        'env_key' => 'APP_KEY',
        'env_value' => 'base64:stored-app-key',
    ]);
    $this->instance->environmentValues()->create([
        'env_key' => 'APP_URL',
        'env_value' => 'https://{{app_instance.domain}}/{{app_instance.environment}}',
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
    $this->action = new TransferAppInstanceAction(
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
        new AppInstanceEnvironmentContextResolver,
        $this->reader,
        new AppInstanceEnvironmentImporter,
        new AppInstanceEnvironmentStore(
            new AppInstanceEnvironmentContextResolver,
            new AppInstanceEnvironmentValidator,
        ),
        new AppInstanceEnvironmentRenderer,
        $this->writer,
        new RouteStateResolver,
        $this->projection,
        $this->projection,
        app(DevelopmentProjectionOperationLock::class),
        $this->routerLock,
    );
    $this->data = new TransferAppInstanceData(
        nodeId: $this->destinationNode->id,
        name: null,
        sqliteSourcePath: null,
    );
});

it('transfers a development AppInstance to another app-dev Node in the same Cluster', function (): void {
    $this->destinationNode->update([
        'cluster_id' => $this->sourceCluster->id,
        'tld' => null,
    ]);
    $result = $this->action->execute($this->instance, $this->data);
    $instance = $result['appInstance'];
    $transfer = $result['transfer'];
    $route = $instance->authoritativeRoute();

    expect($result['created'])->toBeTrue()
        ->and($instance->id)->toBe($this->instance->id)
        ->and($instance->app_id)->toBe($this->orbitApp->id)
        ->and($instance->node_id)->toBe($this->destinationNode->id)
        ->and($instance->name)->toBe('web')
        ->and($instance->checkout_path)->toBe('/srv/orbit/apps/shop/web')
        ->and($instance->source_layout)->toBe('checkout')
        ->and($route?->id)->toBe($this->route->id)
        ->and($route?->domain)->toBe('web.shop.dev.orbit')
        ->and($route?->cluster_id)->toBe($this->sourceCluster->id)
        ->and($transfer->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($transfer->current_step)->toBe(AppInstanceTransferStep::Completed)
        ->and($transfer->cleanup_completed ?? $transfer->completed_at)->not->toBeNull()
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup'])
        ->and($this->runtime->calls)->toBe(['pause', 'relocate', 'activate', 'cleanup'])
        ->and($this->sqlite->calls)->toBeEmpty()
        ->and($this->projection->httpChecks)->toBe(0)
        ->and($this->process->refresh()->id)->toBe($this->process->id)
        ->and($this->process->desired_state)->toBe(DesiredProcessState::Running)
        ->and($this->process->working_directory)->toBe('/srv/orbit/apps/shop/web')
        ->and($this->schedule->refresh()->id)->toBe($this->schedule->id)
        ->and($this->schedule->host_node_id)->toBe($this->destinationNode->id)
        ->and($this->schedule->desired_timer_state)->toBe(DesiredTimerState::Enabled)
        ->and($this->writer->path)->toBe('/srv/orbit/apps/shop/web')
        ->and($this->writer->domain)->toBe('web.shop.dev.orbit')
        ->and($this->writer->contents)
        ->toBe("APP_KEY=\"base64:stored-app-key\"\nAPP_URL=\"https://web.shop.dev.orbit/development\"\nNEW_FROM_ENV=\"imported\"\n");
});

it('transfers a development AppInstance across Clusters and replaces a generated domain', function (): void {
    $this->destinationCluster->update(['tld' => 'other.orbit']);
    $this->destinationNode->update(['tld' => null]);
    $result = $this->action->execute($this->instance, $this->data);
    $instance = $result['appInstance'];
    $route = $instance->authoritativeRoute();

    expect($instance->node_id)->toBe($this->destinationNode->id)
        ->and($route?->id)->not->toBe($this->route->id)
        ->and($route?->domain)->toBe('web.shop.other.orbit')
        ->and($route?->cluster_id)->toBe($this->destinationCluster->id)
        ->and($route?->provenance)->toBe(RouteProvenance::Generated)
        ->and(Route::query()->whereKey($this->route->id)->exists())->toBeFalse();
});

it('rolls back every Route preparation write when its next database boundary fails', function (string $trigger): void {
    $sourceRoute = $this->route->getAttributes();
    $sourceTargets = $this->route->targets()->get()->toArray();
    $this->travel(1)->seconds();
    $this->freezeSecond();
    DB::unprepared($trigger);

    try {
        expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER transfer_preparation_failure');
    }

    $transfer = AppInstanceTransfer::query()->sole();
    expect($this->route->refresh()->getAttributes())->toBe($sourceRoute);
    expect($this->route->targets()->get()->toArray())->toBe($sourceTargets);
    $this->assertDatabaseCount('routes', 1);
    $this->assertDatabaseCount('route_targets', 1);
    expect($transfer->destination_route_id)->toBeNull();
    expect($transfer->cutover_at)->toBeNull();
    expect($transfer->current_step)->toBe(AppInstanceTransferStep::Reserved);
    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);
    expect($this->runtime->calls)->toBe(['pause', 'restore']);
    expect($this->projection->calls)->toBe([]);

    $retry = $this->action->execute($this->instance, $this->data);

    expect($retry['created'])->toBeFalse();
    expect($retry['transfer']->id)->toBe($transfer->id);
    expect($retry['transfer']->status)->toBe(AppInstanceTransferStatus::Completed);
    $this->assertDatabaseCount('routes', 1);
    $this->assertDatabaseCount('route_targets', 1);
})->with([
    'candidate created' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_preparation_failure BEFORE INSERT ON route_targets
        WHEN (SELECT replaces_route_id FROM routes WHERE id = NEW.route_id) IS NOT NULL
        BEGIN SELECT RAISE(ABORT, 'injected target failure'); END
        SQL,
    'target inserted' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_preparation_failure BEFORE UPDATE ON routes
        WHEN NEW.replaced_by_route_id IS NOT NULL AND OLD.replaced_by_route_id IS NULL
        BEGIN SELECT RAISE(ABORT, 'injected pointer failure'); END
        SQL,
    'source pointer updated' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_preparation_failure BEFORE UPDATE ON app_instance_transfers
        WHEN NEW.destination_route_id IS NOT NULL AND OLD.destination_route_id IS NULL
        BEGIN SELECT RAISE(ABORT, 'injected recovery identity failure'); END
        SQL,
    'recovery identity persisted' => <<<'SQL'
        CREATE TEMP TRIGGER transfer_preparation_failure AFTER UPDATE ON app_instance_transfers
        WHEN NEW.destination_route_id IS NOT NULL AND OLD.destination_route_id IS NULL
        BEGIN SELECT RAISE(FAIL, 'injected recovery checkpoint failure'); END
        SQL,
]);

it('records the Route recovery identity only together with its prepared checkpoint', function (bool $sameDomain): void {
    if ($sameDomain) {
        $this->destinationNode->update(['cluster_id' => $this->sourceCluster->id, 'tld' => null]);
    }
    DB::unprepared(<<<'SQL'
        CREATE TEMP TRIGGER transfer_preparation_failure BEFORE UPDATE ON app_instance_transfers
        WHEN NEW.destination_route_id IS NOT NULL AND OLD.destination_route_id IS NULL
            AND NEW.current_step <> 'route-prepared'
        BEGIN SELECT RAISE(ABORT, 'uncheckpointed recovery identity'); END
        SQL);

    try {
        $result = $this->action->execute($this->instance, $this->data);
    } finally {
        DB::unprepared('DROP TRIGGER transfer_preparation_failure');
    }

    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed);
    expect($result['transfer']->destination_route_id)->toBe($result['appInstance']->routes->sole()->id);
})->with(['same domain' => true, 'changed domain' => false]);

it('rolls back same-domain Route evidence when the prepared checkpoint cannot persist', function (): void {
    $this->destinationNode->update(['cluster_id' => $this->sourceCluster->id, 'tld' => null]);
    $sourceRoute = $this->route->getAttributes();
    DB::unprepared(<<<'SQL'
        CREATE TEMP TRIGGER transfer_preparation_failure BEFORE UPDATE ON app_instance_transfers
        WHEN NEW.current_step = 'route-prepared'
        BEGIN SELECT RAISE(ABORT, 'injected checkpoint failure'); END
        SQL);

    try {
        expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER transfer_preparation_failure');
    }

    expect($this->route->refresh()->getAttributes())->toBe($sourceRoute);
    expect(AppInstanceTransfer::query()->sole()->destination_route_id)->toBeNull();
    $this->assertDatabaseCount('routes', 1);
    $this->assertDatabaseCount('route_targets', 1);
});

it('resumes recorded Route preparation with the same candidate identity after interruption', function (bool $sameDomain, AppInstanceTransferStep $step): void {
    if ($sameDomain) {
        $this->destinationNode->update(['cluster_id' => $this->sourceCluster->id, 'tld' => null]);
    }
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, $step);
    $candidateId = $transfer->destination_route_id;

    $result = $this->action->execute($this->instance, $this->data);

    expect($result['created'])->toBeFalse();
    expect($result['transfer']->id)->toBe($transfer->id);
    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed);
    expect($result['appInstance']->routes->sole()->id)->toBe($candidateId);
    expect($result['appInstance']->routes->sole()->domain)->toBe($transfer->destination_domain);
    expect($result['appInstance']->routes->sole()->provenance)->toBe(RouteProvenance::Generated);
    expect($result['appInstance']->routes->sole()->publication)->toBe(RoutePublication::Private);
    expect($result['appInstance']->routes->sole()->cluster_id)->toBe($this->destinationNode->cluster_id);
    expect($result['appInstance']->routes->sole()->targets->sole()->app_instance_id)->toBe($this->instance->id);
    expect($result['appInstance']->routes->sole()->targets->sole()->position)->toBe(0);
    expect($this->sources->calls)->toBe(['cleanup']);
    expect($this->writer->contents)->toBeNull();
    expect($this->runtime->calls)->toBe(['relocate', 'activate', 'cleanup']);
    $this->assertDatabaseCount('routes', 1);
    $this->assertDatabaseCount('route_targets', 1);
})->with(['same domain' => true, 'changed domain' => false])
    ->with([AppInstanceTransferStep::RuntimeRelocated, AppInstanceTransferStep::RoutePrepared]);

it('refuses changed recorded replacement evidence before transfer cutover', function (string $change): void {
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, AppInstanceTransferStep::RuntimeRelocated);
    $candidate = Route::query()->findOrFail($transfer->destination_route_id);
    if ($change === 'domain') {
        $transfer->update(['destination_domain' => 'unexpected.shop.other.orbit']);
    } elseif ($change === 'placement') {
        $candidate->update(['generation_basis_node_id' => $this->sourceNode->id]);
    } elseif ($change === 'pointer') {
        $this->route->update(['replaced_by_route_id' => null]);
    } else {
        $candidate->targets()->update(['position' => 1]);
    }

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.lifecycle_conflict');
        });

    expect($transfer->refresh()->cutover_at)->toBeNull();
    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);
    expect($this->projection->calls)->toBe([]);
    expect($this->runtime->calls)->toBe(['restore']);
    expect($this->route->refresh()->status)->toBe(RouteStatus::Active);
})->with(['domain', 'placement', 'pointer', 'position']);

it('refuses changed same-domain recovery evidence before transfer cutover', function (): void {
    $this->destinationNode->update(['cluster_id' => $this->sourceCluster->id, 'tld' => null]);
    $transfer = orb245_prepared_transfer($this->instance, $this->route, $this->destinationNode, AppInstanceTransferStep::RuntimeRelocated);
    $candidate = Route::query()->create([
        'app_id' => $this->instance->app_id,
        'cluster_id' => $this->sourceCluster->id,
        'domain' => 'unexpected.dev.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $this->route->id,
        'replacement_step' => RouteReplacementStep::Reserved,
    ]);
    $candidate->targets()->create(['app_instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->update(['replaced_by_route_id' => $candidate->id]);

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.lifecycle_conflict');
        });

    expect($transfer->refresh()->cutover_at)->toBeNull();
    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id);
    expect($this->projection->calls)->toBe([]);
    $this->assertModelExists($candidate);
});

it('keeps an explicit Route identity while moving its scope', function (): void {
    $this->instance->update([
        'status' => AppInstanceState::Reserved,
        'provisioning_step' => 'reserved',
    ]);
    $this->route->targets()->delete();
    $this->route->delete();
    $this->instance->refresh();
    $this->route = orb245_route($this->instance, 'shop.example.test', RouteProvenance::Explicit);
    $this->instance->update([
        'status' => AppInstanceState::Active,
        'provisioning_step' => 'active',
    ]);
    $this->destinationNode->update(['tld' => null]);

    $result = $this->action->execute($this->instance, $this->data);
    $route = $result['appInstance']->authoritativeRoute();

    expect($route?->id)->toBe($this->route->id)
        ->and($route?->domain)->toBe('shop.example.test')
        ->and($route?->cluster_id)->toBe($this->destinationCluster->id);
});

it('finalizes a generated Route replacement so environment access and immediate reverse transfer succeed', function (): void {
    $forward = $this->action->execute($this->instance, $this->data);
    $route = $forward['appInstance']->authoritativeRoute();

    expect($route->replacement_step)->toBeNull()
        ->and($route->replaces_route_id)->toBeNull()
        ->and($route->replaced_by_route_id)->toBeNull();
    $context = new AppInstanceEnvironmentContextResolver()->resolve($forward['appInstance'], true);
    expect($context->nodeId)->toBe($this->destinationNode->id)
        ->and($context->routeDomain)->toBe('web.shop.other.orbit');

    $reverse = $this->action->execute($forward['appInstance'], new TransferAppInstanceData(
        nodeId: $this->sourceNode->id,
        name: null,
        sqliteSourcePath: null,
    ));

    expect($reverse['appInstance']->id)->toBe($this->instance->id)
        ->and($reverse['appInstance']->node_id)->toBe($this->sourceNode->id)
        ->and($reverse['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($reverse['appInstance']->routes)->toHaveCount(1);
    expect(new AppInstanceEnvironmentContextResolver()->resolve($reverse['appInstance'], true)->routeDomain)
        ->toBe('web.shop.dev.orbit');
});

it('owns the source Cluster Router before waiting for the environment lock shared with Cluster updates', function (): void {
    $this->environmentLock->beforeRun = function (): void {
        expect($this->routerLock->ownedClusterId)->toBe($this->sourceCluster->id);
    };

    $result = $this->action->execute($this->instance, $this->data);

    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($this->routerLock->ownedClusterId)->toBeNull();
});

it('returns a completed legacy transfer without requiring original Router evidence', function (): void {
    $completed = $this->action->execute($this->instance, $this->data);
    $completed['transfer']->update(['source_router_node_id' => null]);
    $calls = $this->sources->calls;

    $result = $this->action->execute($completed['appInstance'], $this->data);

    expect($result['created'])->toBeFalse()
        ->and($result['transfer']->id)->toBe($completed['transfer']->id)
        ->and($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($this->sources->calls)->toBe($calls);
});

it('retains the source Route and Vite reservation until projection retirement can be retried', function (): void {
    $this->projection->failRetirementOnce = true;
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    $transfer = AppInstanceTransfer::query()->sole();
    $destination = Route::query()->findOrFail($transfer->destination_route_id);

    expect($this->route->refresh()->status)->toBe(RouteStatus::Retiring)
        ->and($destination->replacement_step)->toBe(RouteReplacementStep::DatabaseCutover)
        ->and($transfer->source_router_node_id)->toBe($this->sourceCluster->routerAssignment->node_id)
        ->and($transfer->completed_at)->toBeNull()
        ->and($this->sources->calls)->toBe(['capture', 'materialize'])
        ->and($this->runtime->calls)->toBe(['pause', 'relocate', 'activate']);
    expect(DB::table('vite_port_assignments')->where('app_instance_id', $this->instance->id)->count())->toBe(2);

    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($this->projection->calls)->toBe(['converge', 'retire', 'retire'])
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup'])
        ->and($this->runtime->calls)->not->toContain('restore');
    $this->assertModelMissing($this->route);
});

it('refuses cleanup before remote deletion when the replacement ownership changed', function (): void {
    $this->projection->failRetirementOnce = true;
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    $transfer = AppInstanceTransfer::query()->sole();
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

it('retains legacy post-cutover state when the original Router identity is unknown', function (): void {
    $this->projection->failRetirementOnce = true;
    expect(fn () => $this->action->execute($this->instance, $this->data))->toThrow(ResourceOperationException::class);
    $transfer = AppInstanceTransfer::query()->sole();
    $transfer->update(['source_router_node_id' => null]);

    expect(fn () => $this->action->execute($this->instance->refresh(), $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_source_router_unknown'));

    expect($this->projection->calls)->toBe(['converge', 'retire'])
        ->and($this->sources->calls)->toBe(['capture', 'materialize'])
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
        $test->instance->update(['environment' => 'production']);
    }, 'instance.production_refused'],
    'inactive instance' => [function (object $test): void {
        $test->instance->update(['status' => AppInstanceState::Reserved]);
    }, 'instance.lifecycle_conflict'],
    'migration required' => [function (object $test): void {
        $test->instance->update(['migration_required' => true]);
    }, 'instance.migration_required'],
    'same Node' => [function (object $test): void {
        $test->data = new TransferAppInstanceData($test->sourceNode->id, null, null);
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
    AppInstance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->destinationNode->id,
        'name' => 'other',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/shop/web',
        'branch' => 'main',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
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
    $data = new TransferAppInstanceData($this->destinationNode->id, 'preview', null);
    $result = $this->action->execute($this->instance, $data);
    $instance = $result['appInstance'];
    $route = $instance->authoritativeRoute();

    expect($instance->name)->toBe('preview')
        ->and($instance->checkout_path)->toBe('/srv/orbit/apps/shop/preview')
        ->and($route?->domain)->toBe('preview.shop.other.orbit');
});

it('rejects a colliding rename identity and leaves the original name unchanged', function (): void {
    AppInstance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->destinationNode->id,
        'name' => 'preview',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/shop/preview',
        'branch' => 'main',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);

    expect(fn () => $this->action->execute(
        $this->instance,
        new TransferAppInstanceData($this->destinationNode->id, 'preview', null),
    ))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.identity_conflict'));

    expect($this->instance->refresh()->name)->toBe('web')
        ->and($this->sources->calls)->toBeEmpty();
});

it('captures source as an independent destination checkout without mutating source Git', function (): void {
    $this->action->execute($this->instance, $this->data);

    expect($this->sources->captures[0]->detached)->toBeTrue()
        ->and($this->sources->captures[0]->head)->toBe(str_repeat('a', 40))
        ->and($this->sources->captures[0]->refs)->toBe(['main', 'unpublished'])
        ->and($this->sources->mutatedSource)->toBeFalse()
        ->and($this->sources->materialized[0]->layout)->toBe(AppInstanceSourceLayout::Checkout)
        ->and($this->sources->materialized[0]->path)->toBe('/srv/orbit/apps/shop/web');
});

it('converts a source worktree into an independent destination checkout and preserves the common repository', function (): void {
    $this->instance->update([
        'source_layout' => AppInstanceSourceLayout::Worktree,
        'registration_common_repository_path' => '/home/orbit/.orbit/worktrees/shop.git',
    ]);
    $this->sources->layout = AppInstanceSourceLayout::Worktree;
    $this->sources->common = '/home/orbit/.orbit/worktrees/shop.git';

    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['appInstance']->source_layout)->toBe('checkout')
        ->and($this->sources->cleanupCommon)->toBe('/home/orbit/.orbit/worktrees/shop.git')
        ->and($this->sources->deletedCommon)->toBeFalse()
        ->and($this->sources->materialized[0]->layout)->toBe(AppInstanceSourceLayout::Checkout);
});

it('transfers only the selected SQLite snapshot after the source pause', function (): void {
    $data = new TransferAppInstanceData(
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

it('imports source .env without overwriting stored keys and rebuilds destination values', function (): void {
    $this->reader->contents = "APP_KEY=from-file\nNEW_FROM_ENV=imported\n";
    $this->action->execute($this->instance, $this->data);

    $stored = $this->instance->environmentValues()->pluck('env_value', 'env_key')->all();

    expect($stored['APP_KEY'])->toBe('base64:stored-app-key')
        ->and($stored['NEW_FROM_ENV'])->toBe('imported')
        ->and($this->writer->contents)->not->toContain('from-file')
        ->and(json_encode($this->writer->observed))->not->toContain('from-file');
});

it('preserves source authority and stored configuration when environment observation or parsing fails', function (
    CommandResult|Throwable $observation,
    string $errorCode,
): void {
    $ssh = new TransferEnvironmentObservationSsh($observation);
    $this->reader->delegate = transfer_environment_access($ssh);
    $storedBefore = DB::table('app_instance_environment_values')
        ->where('app_instance_id', $this->instance->id)->orderBy('env_key')->pluck('env_value', 'env_key')->all();
    $sourcePath = $this->instance->checkout_path;

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(function (ResourceOperationException $exception) use ($errorCode): void {
            expect($exception->errorCode)->toBe($errorCode)
                ->and($exception->getMessage())->not->toContain('environment-secret-sentinel')
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->details)->toBeEmpty();
        });

    $transfer = AppInstanceTransfer::query()->where('app_instance_id', $this->instance->id)->sole();
    expect($transfer->status)->toBe(AppInstanceTransferStatus::Failed)
        ->and($transfer->failed_step)->toBe(AppInstanceTransferStep::SqliteTransferred)
        ->and($transfer->error_code)->toBe($errorCode)
        ->and($transfer->cutover_at)->toBeNull()
        ->and($transfer->completed_at)->toBeNull()
        ->and($transfer->destination_route_id)->toBeNull()
        ->and(json_encode($transfer->toArray(), JSON_THROW_ON_ERROR))->not->toContain('environment-secret-sentinel');
    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id)
        ->and($this->instance->checkout_path)->toBe($sourcePath)
        ->and($this->instance->authoritativeRoute()?->id)->toBe($this->route->id)
        ->and($this->route->refresh()->status)->toBe(RouteStatus::Active)
        ->and($this->route->replaced_by_route_id)->toBeNull()
        ->and($this->process->refresh()->working_directory)->toBe($sourcePath)
        ->and($this->process->desired_state)->toBe(DesiredProcessState::Running)
        ->and($this->schedule->refresh()->host_node_id)->toBe($this->sourceNode->id)
        ->and($this->schedule->desired_timer_state)->toBe(DesiredTimerState::Enabled);
    expect(DB::table('app_instance_environment_values')
        ->where('app_instance_id', $this->instance->id)->orderBy('env_key')->pluck('env_value', 'env_key')->all())
        ->toBe($storedBefore);
    expect($this->writer->contents)->toBeNull()
        ->and($this->projection->calls)->toBeEmpty()
        ->and($this->runtime->calls)->toBe(['pause', 'restore'])
        ->and($this->sources->calls)->toBe(['capture', 'materialize'])
        ->and($this->sources->discarded)->toBe(['/srv/orbit/apps/shop/web'])
        ->and($ssh->commands)->toHaveCount(1)
        ->and($ssh->commands[0]->arguments)->toContain('read', $sourcePath)
        ->and($ssh->commands[0]->arguments)->not->toContain('environment-secret-sentinel');

    $ssh->observation = new CommandResult(
        0,
        base64_encode("APP_KEY=from-file\nSOURCE_ONLY=environment-secret-sentinel\n"),
        '',
        1,
        false,
    );
    $result = $this->action->execute($this->instance->refresh(), $this->data);
    $imported = $this->instance->environmentValues()->where('env_key', 'SOURCE_ONLY')->sole();

    expect($result['created'])->toBeFalse()
        ->and($result['transfer']->id)->toBe($transfer->id)
        ->and($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($result['appInstance']->node_id)->toBe($this->destinationNode->id)
        ->and($imported->env_value)->toBe('environment-secret-sentinel')
        ->and($imported->getRawOriginal('env_value'))->not->toContain('environment-secret-sentinel')
        ->and($this->instance->environmentValues()->where('env_key', 'APP_KEY')->sole()->env_value)->toBe('base64:stored-app-key')
        ->and($this->writer->contents)->toContain('SOURCE_ONLY="environment-secret-sentinel"')
        ->and(json_encode($this->writer->observed, JSON_THROW_ON_ERROR))->not->toContain('environment-secret-sentinel')
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'capture', 'materialize', 'cleanup']);
})->with([
    'reader refusal' => [new CommandResult(42, "REFUSED\n", '', 1, false), 'env.import_preflight_failed'],
    'transport failure' => [new RuntimeException('environment-secret-sentinel'), 'env.import_preflight_failed'],
    'nonzero result' => [new CommandResult(1, 'environment-secret-sentinel', 'environment-secret-sentinel', 1, false), 'env.import_preflight_failed'],
    'truncated result' => [new CommandResult(0, base64_encode('SOURCE_ONLY=environment-secret-sentinel'), '', 1, true), 'env.import_preflight_failed'],
    'malformed result' => [new CommandResult(0, '%environment-secret-sentinel%', '', 1, false), 'env.import_preflight_failed'],
    'unexpected diagnostics' => [new CommandResult(0, base64_encode('SOURCE_ONLY=environment-secret-sentinel'), 'environment-secret-sentinel', 1, false), 'env.import_preflight_failed'],
    'malformed dotenv' => [new CommandResult(0, base64_encode("SOURCE_ONLY environment-secret-sentinel\n"), '', 1, false), 'env.import_invalid'],
]);

it('accepts a positively observed empty environment file without losing stored keys', function (): void {
    $ssh = new TransferEnvironmentObservationSsh(new CommandResult(0, '', '', 1, false));
    $this->reader->delegate = transfer_environment_access($ssh);

    $result = $this->action->execute($this->instance, $this->data);

    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($this->instance->environmentValues()->orderBy('env_key')->pluck('env_value', 'env_key')->all())->toBe([
            'APP_KEY' => 'base64:stored-app-key',
            'APP_URL' => 'https://{{app_instance.domain}}/{{app_instance.environment}}',
        ])
        ->and($this->writer->contents)->toBe("APP_KEY=\"base64:stored-app-key\"\nAPP_URL=\"https://web.shop.other.orbit/development\"\n")
        ->and($ssh->commands)->toHaveCount(1);
});

it('restores the source and discards destination state when transfer fails before cutover', function (): void {
    $this->sources->failMaterialize = true;

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(ResourceOperationException::class);

    $transfer = AppInstanceTransfer::query()->where('app_instance_id', $this->instance->id)->sole();

    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id)
        ->and($this->instance->name)->toBe('web')
        ->and($this->runtime->calls)->toBe(['restore'])
        ->and($this->sources->discarded)->toBe(['/srv/orbit/apps/shop/web'])
        ->and($transfer->cutover_at)->toBeNull()
        ->and($transfer->current_step)->toBe(AppInstanceTransferStep::Reserved)
        ->and($transfer->status)->toBe(AppInstanceTransferStatus::Failed);

    expect(fn () => $this->action->execute(
        $this->instance->refresh(),
        new TransferAppInstanceData($this->destinationNode->id, 'other', null),
    ))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_retry_conflict'));

    expect(DB::table('vite_port_assignments')->where('app_instance_id', $this->instance->id)->pluck('node_id')->all())->toBe([$this->sourceNode->id]);
    $this->sources->failMaterialize = false;
    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['created'])->toBeFalse()
        ->and($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($result['appInstance']->node_id)->toBe($this->destinationNode->id);
});

it('continues only forward after cutover and does not recopy source', function (): void {
    $this->projection->failOnce = true;

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(ResourceOperationException::class);

    $transfer = AppInstanceTransfer::query()->where('app_instance_id', $this->instance->id)->sole();

    expect($this->instance->refresh()->node_id)->toBe($this->destinationNode->id)
        ->and($transfer->cutover_at)->not->toBeNull()
        ->and($this->runtime->calls)->not->toContain('restore')
        ->and($this->sources->calls)->toBe(['capture', 'materialize']);

    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup'])
        ->and($this->runtime->calls)->not->toContain('restore');
});

it('reports incomplete old-placement cleanup and retries only cleanup', function (): void {
    $this->sources->cleanupIncomplete = true;

    expect(fn () => $this->action->execute($this->instance, $this->data))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_cleanup_incomplete'));

    $transfer = AppInstanceTransfer::query()->where('app_instance_id', $this->instance->id)->sole();

    expect($this->instance->refresh()->node_id)->toBe($this->destinationNode->id)
        ->and($transfer->recovery_evidence)->toHaveKey('incomplete')
        ->and($this->route->refresh()->status)->toBe(RouteStatus::Retiring)
        ->and(Route::query()->findOrFail($transfer->destination_route_id)->replacement_step)->toBe(RouteReplacementStep::DatabaseCutover)
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup']);

    expect(DB::table('vite_port_assignments')->where('app_instance_id', $this->instance->id)->count())->toBe(2);
    $this->sources->cleanupIncomplete = false;
    $result = $this->action->execute($this->instance->refresh(), $this->data);

    expect($result['transfer']->status)->toBe(AppInstanceTransferStatus::Completed)
        ->and($this->sources->calls)->toBe(['capture', 'materialize', 'cleanup', 'cleanup']);
    expect(DB::table('vite_port_assignments')->where('app_instance_id', $this->instance->id)->pluck('node_id')->all())->toBe([$this->destinationNode->id]);
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

function orb245_prepared_transfer(AppInstance $instance, Route $source, Node $destination, AppInstanceTransferStep $step): AppInstanceTransfer
{
    app(VitePortAllocator::class)->assign($instance);
    app(VitePortAllocator::class)->assign($instance, $destination);
    $domain = $source->cluster_id === $destination->cluster_id ? $source->domain : 'web.shop.other.orbit';
    $candidate = $source;
    if ($domain !== $source->domain) {
        $candidate = Route::query()->create([
            'app_id' => $instance->app_id,
            'cluster_id' => $destination->cluster_id,
            'generation_basis_node_id' => $destination->id,
            'domain' => $domain,
            'provenance' => RouteProvenance::Generated,
            'publication' => $source->publication,
            'status' => RouteStatus::Pending,
            'replaces_route_id' => $source->id,
            'replacement_step' => RouteReplacementStep::Reserved,
        ]);
        $candidate->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
        $source->update(['replaced_by_route_id' => $candidate->id]);
    }

    return AppInstanceTransfer::query()->create([
        'app_instance_id' => $instance->id,
        'source_node_id' => $instance->node_id,
        'source_router_node_id' => $instance->node->cluster->routerAssignment->node_id,
        'destination_node_id' => $destination->id,
        'requested_name' => null,
        'destination_name' => $instance->name,
        'destination_path' => '/srv/orbit/apps/shop/web',
        'destination_domain' => $domain,
        'source_layout' => $instance->source_layout,
        'source_path' => $instance->checkout_path,
        'source_route_id' => $source->id,
        'destination_route_id' => $candidate->id,
        'status' => AppInstanceTransferStatus::InProgress,
        'current_step' => $step,
    ]);
}

function orb245_instance(OrbitApp $app, Node $node, string $name, string $layout): AppInstance
{
    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => 'development',
        'source_layout' => $layout,
        'checkout_path' => "/srv/orbit/apps/{$app->slug}/{$name}",
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
}

function orb245_route(AppInstance $instance, string $domain, RouteProvenance $provenance): Route
{
    $route = Route::query()->create([
        'app_id' => $instance->app_id,
        'cluster_id' => $instance->node->cluster_id,
        'generation_basis_node_id' => $provenance === RouteProvenance::Generated ? $instance->node_id : null,
        'domain' => $domain,
        'provenance' => $provenance,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'app_instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}

function transfer_environment_access(SshExecutor $ssh): RemoteAppInstanceEnvironmentAccess
{
    return new RemoteAppInstanceEnvironmentAccess(
        $ssh,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/transfer-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 synthetic';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/transfer-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
}

final class TransferEnvironmentObservationSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    public function __construct(public CommandResult|Throwable $observation) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        if ($this->observation instanceof Throwable) {
            throw $this->observation;
        }

        return $this->observation;
    }
}
