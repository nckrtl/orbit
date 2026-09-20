<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeAction;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Herdr\HerdrObserverPublisher;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRemovalException;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleDependentCleaner;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Infrastructure\Firewall\UfwRuleOwnership;
use App\Infrastructure\Metrics\MetricsCadvisorRuntime;
use App\Infrastructure\Metrics\MetricsExporterRuntime;
use App\Infrastructure\Metrics\MetricsExporterState;
use App\Infrastructure\Metrics\ServiceMetricsRuntime;
use App\Infrastructure\Nodes\NativeNodeProvisioningLock;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\FirewallRule;
use App\Models\HerdrSession;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;
use App\Models\Schedule;
use Tests\Support\FakeHerdrObserverPublisher;
use Tests\Support\FakeNodeRoleFirewallManager;
use Tests\Support\FakeRouterLanIngressReconciler;

beforeEach(function (): void {
    $services = Mockery::mock(ServiceMetricsRuntime::class);
    $services->shouldReceive('snapshot')->andReturn('{}');
    $services->shouldReceive('converge');
    $services->shouldReceive('restore');
    app()->instance(ServiceMetricsRuntime::class, $services);
    $this->dns = new RemoveNodeFakeDnsManager;
    $this->peers = new RemoveNodeFakePeerProjection;
    $this->metricsAccess = new RemoveNodeFakeMetricsAccessRevoker;
    $this->firewall = new FakeNodeRoleFirewallManager;
    $this->processRuntime = new RemoveNodeFakeProcessRuntimeManager;
    $this->herdrObservers = new FakeHerdrObserverPublisher;
    app()->instance(NodeRoleFirewallManager::class, $this->firewall);
    app()->instance(PrivateDnsManager::class, $this->dns);
    app()->instance('App\\Domain\\WireGuard\\GatewayPeerProjectionManager', $this->peers);
    app()->instance(MetricsAccessRevoker::class, $this->metricsAccess);
    app()->instance(ProcessRuntimeManager::class, $this->processRuntime);
    app()->instance(HerdrObserverPublisher::class, $this->herdrObservers);
});

it('rejects removal contention before remote effects or state writes', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    app()->instance(NodeProvisioningLock::class, new class implements NodeProvisioningLock
    {
        public function run(string $nodeName, Closure $callback): mixed
        {
            throw new NodeProvisioningLockException($nodeName);
        }
    });

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertConflict()
        ->assertJsonPath('error.code', 'node.provisioning_busy')
        ->assertJsonPath('error.message', 'Node [retired] is already changing.');

    expect($target->refresh()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->peers->removed)
        ->toBeEmpty()
        ->and($this->dns->convergences)
        ->toBe(0)
        ->and($this->metricsAccess->calls)
        ->toBe(0);
});

it('re-reads identity after acquiring the lifecycle guard', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    app()->instance(NodeProvisioningLock::class, new class($target) implements NodeProvisioningLock
    {
        public function __construct(private Node $target) {}

        public function run(string $nodeName, Closure $callback): mixed
        {
            $this->target->delete();

            return $callback();
        }
    });

    expect(fn () => app(RemoveNodeAction::class)->execute($target, $caller))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('node.not_found')->and($exception->status)->toBe(404);
        });

    expect($this->peers->removed)->toBeEmpty()
        ->and($this->dns->convergences)
        ->toBe(0);
});

it('re-reads removal eligibility after acquiring the lifecycle guard', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'app-dev', wireguardIp: '10.44.0.3');
    $cluster = Cluster::query()->create(['name' => 'development', 'state' => ClusterState::Active]);
    $target->update(['cluster_id' => $cluster->id]);
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    app()->instance(NodeProvisioningLock::class, new class($target, $app) implements NodeProvisioningLock
    {
        public function __construct(private Node $target, private OrbitApp $app) {}

        public function run(string $nodeName, Closure $callback): mixed
        {
            AppInstance::query()->create([
                'app_id' => $this->app->id,
                'node_id' => $this->target->id,
                'name' => 'dev',
                'checkout_path' => '/srv/orbit/apps/acme/dev',
                'branch' => 'dev',
                'starting_commit' => str_repeat('a', 40),
                'status' => AppInstanceState::Active,
            ]);

            return $callback();
        }
    });

    expect(fn () => app(RemoveNodeAction::class)->execute($target, $caller))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'node.has_app_instances');

    expect($target->refresh()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->peers->removed)
        ->toBeEmpty()
        ->and($this->dns->convergences)
        ->toBe(0);
});

it('releases the lifecycle guard after a verification failure', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $this->metricsAccess->failure = new RuntimeException('private Caddy detail');

    expect(fn () => app(RemoveNodeAction::class)->execute($target, $caller))
        ->toThrow(fn (NodeRemovalException $exception): bool => $exception->errorCode === 'node.grafana_access_revocation_failed');

    expect($target->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and((new NativeNodeProvisioningLock)->run($target->name, static fn (): string => 'released'))
        ->toBe('released');
});

it('allows an independent node name to proceed while another lifecycle guard is held', function (): void {
    $holder = new NativeNodeProvisioningLock;
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $held = remove_node_record(name: 'held', wireguardIp: '10.44.0.3');
    $free = remove_node_record(name: 'free', wireguardIp: '10.44.0.4');
    $caller->accessibleNodes()->attach($free);

    $holder->run($held->name, function () use ($caller, $free): void {
        app(RemoveNodeAction::class)->execute($free, $caller);

        expect($free->fresh())->toBeNull();
    });

    expect($held->refresh()->status)->toBe(LifecycleStatus::Active);
});

it('prunes Router LAN ingress after WireGuard removal and before the Node is deleted', function (): void {
    $reconciler = new FakeRouterLanIngressReconciler;
    app()->instance(RouterLanIngressReconciler::class, $reconciler);
    $cluster = Cluster::query()->create(['name' => 'lan-remove']);
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update([
        'cluster_id' => $cluster->id,
        'lan_ip' => '10.20.0.3',
        'wireguard_public_key' => 'TARGET_PUBLIC_KEY',
    ]);

    app(RemoveNodeAction::class)->execute($target, $caller);

    expect($target->fresh())
        ->toBeNull()
        ->and(array_column($reconciler->events, 'phase'))
        ->toBe(['prune'])
        ->and($reconciler->events[0]['nodeOverrides'][$target->id]['status'])
        ->toBe(LifecycleStatus::Removing)
        ->and($this->peers->removed)
        ->toBe([$target->id])
        ->and($this->dns->convergences)
        ->toBe(1);
});

it('restores the Node when Router LAN prune fails after WireGuard removal', function (): void {
    $reconciler = new FakeRouterLanIngressReconciler;
    $reconciler->pruneFailure = new FirewallOperationException(
        step: 'host-firewall',
        errorCode: 'node.firewall_convergence_failed',
        message: 'LAN prune failed.',
    );
    app()->instance(RouterLanIngressReconciler::class, $reconciler);
    $cluster = Cluster::query()->create(['name' => 'lan-remove-failure']);
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update([
        'cluster_id' => $cluster->id,
        'lan_ip' => '10.20.0.3',
        'wireguard_public_key' => 'TARGET_PUBLIC_KEY',
    ]);

    expect(fn () => app(RemoveNodeAction::class)->execute($target, $caller))
        ->toThrow(fn (NodeRemovalException $exception): bool => $exception->errorCode === 'router.lan_ingress_failed');

    expect($target->refresh()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->peers->removed)
        ->toBe([$target->id])
        ->and($this->peers->restored)
        ->toBe([$target->id])
        ->and($target->fresh())
        ->not->toBeNull();
});

it('retries Grafana stream revocation before removing membership', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    $this->metricsAccess->failure = new RuntimeException('private Caddy detail');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node.grafana_access_revocation_failed')
        ->assertJsonPath('error.details.step', 'grafana-access-revocation')
        ->assertJsonMissing(['private Caddy detail']);

    expect($target->refresh()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->peers->removed)
        ->toBeEmpty();

    $this->metricsAccess->failure = null;

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertOk();

    expect($this->metricsAccess->calls)
        ->toBe(2)
        ->and($target->fresh())
        ->toBeNull();
});

it('refuses Node removal around an AppInstance for ordinary and forced offline paths', function (array $body): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'app-dev', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $cluster = Cluster::query()->create(['name' => 'development', 'state' => ClusterState::Active]);
    $target->update(['cluster_id' => $cluster->id]);
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $target->id,
        'name' => 'dev',
        'checkout_path' => '/srv/orbit/apps/acme/dev',
        'branch' => 'dev',
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::Active,
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", $body)
        ->assertConflict()
        ->assertJsonPath('error.code', 'node.has_app_instances');

    expect($target->refresh()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($target->cluster_id)
        ->toBe($cluster->id)
        ->and(AppInstance::query()->count())
        ->toBe(1)
        ->and($this->peers->removed)
        ->toBeEmpty()
        ->and($this->dns->convergences)
        ->toBe(0);
})->with([
    'ordinary' => [['offline' => false, 'force' => false]],
    'forced offline' => [['offline' => true, 'force' => true]],
]);

it('retires Metrics exporter state before removing network projections', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics
        ->shouldReceive('retire')
        ->once()
        ->withArgs(
            static fn (Node $node): bool => $node->is($target) && $node->status === LifecycleStatus::Removing,
        );
    app()->instance(MetricsFleetReconciler::class, $metrics);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertOk();

    expect($this->peers->removed)->toBe([$target->id]);
});

it('refuses Node removal before mutation while the Node owns a Herdr session', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    $process = remove_node_owned_process($target);
    HerdrSession::query()->create([
        'node_id' => $target->id,
        'session' => 'commander-tasks',
        'user' => 'orbit',
        'process_id' => $process->id,
        'observer_port' => 7411,
        'observer_hostname' => 'commander-tasks.herdr.retired.orbit',
        'observer_status' => 'published',
        'status' => LifecycleStatus::Active,
        'publish_observer' => true,
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertConflict()
        ->assertJsonPath('error.code', 'node.has_herdr_sessions');

    expect($target->refresh()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->peers->removed)
        ->toBeEmpty()
        ->and($this->dns->convergences)
        ->toBe(0)
        ->and($this->metricsAccess->calls)
        ->toBe(0)
        ->and($this->firewall->commands)
        ->toBeEmpty()
        ->and($this->processRuntime->commands)
        ->toBeEmpty()
        ->and($this->herdrObservers->retracted)
        ->toBeEmpty();
    $this->assertDatabaseHas('herdr_sessions', ['session' => 'commander-tasks']);
    $this->assertDatabaseHas('processes', ['id' => $process->id]);
});

it('refuses Node removal before mutation while the Node owns a Process', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    $process = remove_node_owned_process($target);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertConflict()
        ->assertJsonPath('error.code', 'node.has_processes');

    expect($target->refresh()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->peers->removed)
        ->toBeEmpty()
        ->and($this->dns->convergences)
        ->toBe(0)
        ->and($this->metricsAccess->calls)
        ->toBe(0)
        ->and($this->firewall->commands)
        ->toBeEmpty()
        ->and($this->processRuntime->commands)
        ->toBeEmpty()
        ->and($this->herdrObservers->retracted)
        ->toBeEmpty();
    $this->assertDatabaseHas('processes', ['id' => $process->id]);
});

it('forgets Node-owned Process and Herdr session records during offline removal without remote cleanup', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    remove_node_offline_probe($target);
    $process = remove_node_owned_process($target);
    HerdrSession::query()->create([
        'node_id' => $target->id,
        'session' => 'commander-tasks',
        'user' => 'orbit',
        'process_id' => $process->id,
        'observer_port' => 7411,
        'observer_hostname' => 'commander-tasks.herdr.retired.orbit',
        'observer_status' => 'published',
        'status' => LifecycleStatus::Active,
        'publish_observer' => true,
    ]);

    app(RemoveNodeAction::class)->execute($target, $caller, offline: true, force: true);

    expect($target->fresh())->toBeNull()
        ->and($this->processRuntime->commands)
        ->toBeEmpty()
        ->and($this->herdrObservers->retracted)
        ->toBeEmpty()
        ->and($this->firewall->commands)
        ->toBeEmpty();
    $this->assertDatabaseMissing('processes', ['id' => $process->id]);
    $this->assertDatabaseMissing('herdr_sessions', ['session' => 'commander-tasks']);
});

it('refuses Node removal before mutation while a Schedule uses the Node', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'scheduled', wireguardIp: '10.44.0.3');
    Schedule::query()->create([
        'target_type' => Node::class,
        'target_id' => $target->id,
        'host_node_id' => $target->id,
        'name' => 'daily',
        'calendar' => 'daily',
        'command' => 'true',
        'timeout_seconds' => 3600,
        'desired_timer_state' => DesiredTimerState::Enabled,
        'status' => LifecycleStatus::Active,
    ]);

    expect(fn () => app(RemoveNodeAction::class)->execute($target, $caller, offline: true, force: true))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'schedule.target_in_use');

    expect($target->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and($this->peers->removed)->toBeEmpty()
        ->and($this->dns->convergences)->toBe(0);
});

it('restores active Metrics selection when exporter retirement fails', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('retire')->once()->andThrow(new RuntimeException('private Metrics failure'));
    $metrics->shouldReceive('reconcile')->once();
    app()->instance(MetricsFleetReconciler::class, $metrics);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node.metrics_reconcile_failed')
        ->assertJsonPath('error.details.step', 'metrics-exporters')
        ->assertJsonMissing(['private Metrics failure']);

    expect($target->fresh()?->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->peers->removed)
        ->toBeEmpty()
        ->and($this->dns->convergences)
        ->toBe(0);
});

it('removes an unreachable node while Metrics is enabled', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'unreachable', wireguardIp: '10.44.0.3');
    $metricsNode = remove_node_record(name: 'metrics', wireguardIp: '10.44.0.4');
    NodeRole::query()->create([
        'node_id' => $metricsNode->id,
        'role' => RoleName::Metrics->value,
        'status' => LifecycleStatus::Active->value,
    ]);
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    // Every SSH call to the removed node fails, exactly as it would while the
    // node is powered off.
    $exporters = new RemoveNodeUnreachableExporterRuntime('unreachable');
    app()->instance(MetricsExporterRuntime::class, $exporters);
    app()->instance(MetricsCadvisorRuntime::class, $exporters);
    app()->instance(MetricsRuntimeLifecycle::class, new RemoveNodeFakeMetricsRuntime);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertOk();

    // The retire of the dead node is attempted and fails, and the remaining
    // fleet still converges afterwards rather than the removal aborting.
    expect($target->fresh())
        ->toBeNull()
        ->and($this->peers->removed)
        ->toBe([$target->id])
        ->and($exporters->events)
        ->toContain('remove:unreachable')
        ->toContain('converge:metrics')
        ->toContain('snapshot:operator')
        ->and(array_filter(
            $exporters->events,
            static fn (string $event): bool => str_ends_with($event, ':unreachable') && $event !== 'remove:unreachable',
        ))
        ->toBeEmpty();
});

it('reconciles a fleet peer that is unreachable without failing the removal', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $metricsNode = remove_node_record(name: 'metrics', wireguardIp: '10.44.0.4');
    $peer = remove_node_record(name: 'dead-peer', wireguardIp: '10.44.0.5');
    NodeRole::query()->create([
        'node_id' => $metricsNode->id,
        'role' => RoleName::Metrics->value,
        'status' => LifecycleStatus::Active->value,
    ]);
    NodeRole::query()->create([
        'node_id' => $peer->id,
        'role' => RoleName::AppProd->value,
        'status' => LifecycleStatus::Active->value,
    ]);
    $caller->accessibleNodes()->attach($target);
    $exporters = new RemoveNodeUnreachableExporterRuntime('dead-peer');
    app()->instance(MetricsExporterRuntime::class, $exporters);
    app()->instance(MetricsCadvisorRuntime::class, $exporters);
    app()->instance(MetricsRuntimeLifecycle::class, new RemoveNodeFakeMetricsRuntime);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertOk();

    expect($target->fresh())
        ->toBeNull()
        ->and(app(ExporterDegradationRepository::class)->get($peer->id))
        ->toBe(ExporterDegradationReason::Unreachable);
});

it('removes only the target WireGuard peer before reconciling DNS', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertOk()
        ->assertJsonPath('data.wireguard_peer_removed', true)
        ->assertJsonPath('data.dns_records_removed', true);

    expect($this->peers->removed)
        ->toBe([$target->id])
        ->and($this->peers->restored)
        ->toBeEmpty()
        ->and($this->dns->convergences)
        ->toBe(1)
        ->and($target->fresh())
        ->toBeNull();
});

it('reopens public SSH over WireGuard before removing the WireGuard peer', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    $peersRemovedAtRestore = null;
    $this->firewall->onRestore = function () use (&$peersRemovedAtRestore): void {
        $peersRemovedAtRestore = $this->peers->removed;
    };

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertOk()
        ->assertJsonPath('data.removed', true)
        ->assertJsonPath('data.wireguard_peer_removed', true);

    expect($this->firewall->restored)
        ->toBe([$target->id])
        ->and($this->firewall->restoredUsers)
        ->toBe(['orbit'])
        ->and($this->firewall->commands)
        ->toBe(['firewall-recovery'])
        ->and($this->processRuntime->commands)
        ->toBeEmpty()
        ->and($this->herdrObservers->retracted)
        ->toBeEmpty()
        ->and($this->herdrObservers->published)
        ->toBeEmpty()
        ->and($peersRemovedAtRestore)
        ->toBe([])
        ->and($this->peers->removed)
        ->toBe([$target->id])
        ->and($target->fresh())
        ->toBeNull();
});

it('refuses removal and keeps the WireGuard peer when public SSH cannot be reopened', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    $this->firewall->restoreFailure = new FirewallOperationException(
        step: 'host-firewall',
        errorCode: 'node.firewall_convergence_failed',
        message: 'private firewall detail',
    );
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('retire')->once();
    $metrics->shouldReceive('reconcile')->once();
    app()->instance(MetricsFleetReconciler::class, $metrics);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node.firewall_recovery_failed')
        ->assertJsonPath('error.details.step', 'firewall-recovery')
        ->assertJsonMissing(['private firewall detail']);

    expect($target->fresh()?->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->peers->removed)
        ->toBeEmpty()
        ->and($this->dns->convergences)
        ->toBe(0);
});

it('returns 502 and retains active state when WireGuard projection fails', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    $this->peers->removeFailure = new RuntimeException('private projection detail');
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('retire')->once();
    $metrics->shouldReceive('reconcile')->once();
    app()->instance(MetricsFleetReconciler::class, $metrics);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node.wireguard_projection_failed')
        ->assertJsonPath('error.details.step', 'wireguard-projection')
        ->assertJsonMissing(['private projection detail']);

    expect($target->fresh()?->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->dns->convergences)
        ->toBe(0)
        ->and($this->peers->restored)
        ->toBeEmpty();
});

it('restores the WireGuard peer and node state when DNS projection fails', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    $this->dns->failure = new RuntimeException('private DNS detail');
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('retire')->once();
    $metrics->shouldReceive('reconcile')->once();
    app()->instance(MetricsFleetReconciler::class, $metrics);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node.dns_projection_failed')
        ->assertJsonPath('error.details.step', 'dns-projection')
        ->assertJsonMissing(['private DNS detail']);

    expect($target->fresh()?->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->peers->removed)
        ->toBe([$target->id])
        ->and($this->peers->restored)
        ->toBe([$target->id]);
});

it('returns a stable rollback error when restoring the WireGuard peer fails', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    $this->dns->failure = new RuntimeException('private DNS detail');
    $this->peers->restoreFailure = new RuntimeException('private rollback detail');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node.removal_rollback_failed')
        ->assertJsonPath('error.details.step', 'wireguard-rollback')
        ->assertJsonMissing(['private DNS detail', 'private rollback detail']);

    expect($target->fresh()?->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->peers->restored)
        ->toBe([$target->id]);
});

it('restores network projections and active state when persistence deletion fails', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'persistence-failure', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    Node::deleting(static function (Node $node): void {
        if ($node->name === 'persistence-failure') {
            throw new RuntimeException('private database detail');
        }
    });
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('retire')->once();
    $metrics->shouldReceive('reconcile')->once();
    app()->instance(MetricsFleetReconciler::class, $metrics);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node.persistence_failed')
        ->assertJsonPath('error.details.step', 'persistence')
        ->assertJsonMissing(['private database detail']);

    expect($target->fresh()?->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->peers->removed)
        ->toBe([$target->id])
        ->and($this->peers->restored)
        ->toBe([$target->id])
        ->and($this->dns->convergences)
        ->toBe(2);
});

it('removes a roleless node without resources and returns the stable projection result', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $requestId = '47783d46-e420-42f6-868d-31dadf54105c';

    $response = $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false]);

    $response
        ->assertOk()
        ->assertHeader('X-Orbit-Request-Id', $requestId)
        ->assertJsonPath('data.id', $target->id)
        ->assertJsonPath('data.name', 'retired')
        ->assertJsonPath('data.removed', true)
        ->assertJsonPath('data.wireguard_peer_removed', false)
        ->assertJsonPath('data.dns_records_removed', true)
        ->assertJsonPath('meta.request_id', $requestId);

    expect($target->fresh())
        ->toBeNull()
        ->and($this->dns->convergences)
        ->toBe(1)
        ->and($this->firewall->restored)
        ->toBe([]);

    $activity = Activity::query()->where('command', 'node:remove')->sole();

    expect($activity->subject_type)
        ->toBe(Node::class)
        ->and($activity->subject_id)
        ->toBe($target->id)
        ->and($activity->target_node_id)
        ->toBeNull()
        ->and($activity->properties?->get('target_node'))
        ->toBe(['id' => $target->id, 'name' => 'retired'])
        ->and($activity->status)
        ->toBe('succeeded');
});

it('returns 409 without side effects when the caller targets itself', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $caller->accessibleNodes()->attach($caller);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$caller->id}", ['offline' => false])
        ->assertConflict()
        ->assertJsonPath('error.code', 'node.self_removal_forbidden');

    expect($caller->fresh())
        ->not
        ->toBeNull()
        ->and($this->dns->convergences)
        ->toBe(0);
});

it('returns 409 when the target still has any role assignment', function (RoleName $role): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $clusterId = null;

    if ($role === RoleName::Ingress) {
        $clusterId = Cluster::query()->create(['name' => 'retired-ingress'])->id;
        $target->update(['cluster_id' => $clusterId]);
    }

    NodeRole::query()->create([
        'node_id' => $target->id,
        'cluster_id' => $clusterId,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertConflict()
        ->assertJsonPath('error.code', match ($role) {
            RoleName::Gateway => 'node.gateway_removal_forbidden',
            RoleName::Vpn => 'node.vpn_removal_forbidden',
            default => 'node.has_roles',
        });

    expect($target->fresh())
        ->not
        ->toBeNull()
        ->and($this->dns->convergences)
        ->toBe(0);
})->with(array_values(array_filter(
    RoleName::cases(),
    static fn (RoleName $role): bool => $role !== RoleName::Router,
)));

it('returns 409 when the target still owns a firewall rule', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    FirewallRule::query()->create([
        'node_id' => $target->id,
        'name' => 'https',
        'action' => 'allow',
        'source' => 'any',
        'protocol' => 'tcp',
        'port' => '443',
        'status' => LifecycleStatus::Active,
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertConflict()
        ->assertJsonPath('error.code', 'node.has_firewall_rules');

    expect($target->fresh())
        ->not
        ->toBeNull()
        ->and($this->dns->convergences)
        ->toBe(0);
});

it('removes an unreachable node holding a role in one command', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    remove_node_offline_probe($target);
    NodeRole::query()->create([
        'node_id' => $target->id,
        'role' => RoleName::AppProd,
        'status' => LifecycleStatus::Active,
    ]);
    $process = remove_node_owned_process($target);
    HerdrSession::query()->create([
        'node_id' => $target->id,
        'session' => 'commander-tasks',
        'user' => 'orbit',
        'process_id' => $process->id,
        'observer_port' => 7411,
        'observer_hostname' => 'commander-tasks.herdr.retired.orbit',
        'observer_status' => 'published',
        'status' => LifecycleStatus::Active,
        'publish_observer' => true,
    ]);
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('reconcile')->atLeast()->once();
    $metrics->shouldReceive('retire')->once();
    app()->instance(MetricsFleetReconciler::class, $metrics);

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['force' => true, 'offline' => true]);

    $response
        ->assertOk()
        ->assertJsonPath('data.removed', true)
        ->assertJsonPath('data.degradation', 'unreachable')
        ->assertJsonPath('data.roles_shed', ['app-prod'])
        ->assertJsonPath('data.wireguard_peer_removed', true)
        ->assertJsonPath(
            'data.follow_up',
            'Discard this node, or clear only the leftovers listed above by hand once it is reachable.',
        );

    expect($response->json('data.retained_on_node'))
        ->toContain('Caddy site configuration and certificates for the app-prod role')
        ->toContain('Orbit firewall rules for the app-prod role')
        ->and($target->fresh())
        ->toBeNull()
        ->and(NodeRole::query()->where('node_id', $target->id)->exists())
        ->toBeFalse()
        ->and($this->peers->removed)
        ->toBe([$target->id])
        ->and($this->firewall->restored)
        ->toBe([])
        ->and($this->firewall->commands)
        ->toBeEmpty()
        ->and($this->processRuntime->commands)
        ->toBeEmpty()
        ->and($this->herdrObservers->retracted)
        ->toBeEmpty();
    $this->assertDatabaseMissing('processes', ['id' => $process->id]);
    $this->assertDatabaseMissing('herdr_sessions', ['session' => 'commander-tasks']);
});

it('refuses an unreachable node without the offline claim and names the flag', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    remove_node_offline_probe($target);
    remove_node_role_fixture($target, RoleName::AppProd);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertConflict()
        ->assertJsonPath('error.code', 'node.has_roles')
        ->assertJsonPath(
            'error.message',
            'Node [retired] still has roles. Remove them, or use --offline if the node is unreachable.',
        );

    expect($target->fresh())
        ->not
        ->toBeNull()
        ->and(NodeRole::query()->where('node_id', $target->id)->exists())
        ->toBeTrue()
        ->and($this->dns->convergences)
        ->toBe(0);
});

it('leaves a reachable node alone even when the offline claim is made', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    remove_node_reachable_probe();
    remove_node_role_fixture($target, RoleName::AppProd);
    $cleaner = new RemoveNodeCountingCleaner;
    app()->instance(NodeRoleDependentCleaner::class, $cleaner);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['force' => true, 'offline' => true])
        ->assertConflict()
        ->assertJsonPath('error.code', 'node.has_roles');

    // The claim is checked, not obeyed: a node that answers keeps the ordinary
    // contract, and nothing on it was touched on the way to the refusal.
    expect($target->fresh())
        ->not
        ->toBeNull()
        ->and(NodeRole::query()->where('node_id', $target->id)->sole()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($cleaner->calls)
        ->toBe(0)
        ->and($this->dns->convergences)
        ->toBe(0)
        ->and($this->peers->removed)
        ->toBe([]);
});

it('never sheds roles from a protected node, whatever the offline claim says', function (RoleName $role): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    remove_node_offline_probe($target);
    NodeRole::query()->create([
        'node_id' => $target->id,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['force' => true, 'offline' => true])
        ->assertConflict()
        ->assertJsonPath('error.code', match ($role) {
            RoleName::Gateway => 'node.gateway_removal_forbidden',
            RoleName::Vpn => 'node.vpn_removal_forbidden',
        });

    expect(NodeRole::query()->where('node_id', $target->id)->exists())
        ->toBeTrue()
        ->and($target->fresh())
        ->not
        ->toBeNull()
        ->and($this->dns->convergences)
        ->toBe(0);
})->with([
    'gateway' => [RoleName::Gateway],
    'vpn' => [RoleName::Vpn],
]);

it('rejects an offline claim that is not a boolean', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['force' => true, 'offline' => 'yes'])
        ->assertStatus(422);

    expect($target->fresh())->not->toBeNull();
});

it('refuses to shed roles from an unreachable node without consent', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    remove_node_offline_probe($target);
    remove_node_role_fixture($target, RoleName::AppProd);

    // Offline removal still requires force. The claim alone must not widen
    // the blast radius.
    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath(
            'error.details.force.0',
            'The force field must be true when offline removal is requested.',
        );

    expect($target->fresh())
        ->not
        ->toBeNull()
        ->and(NodeRole::query()->where('node_id', $target->id)->sole()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->dns->convergences)
        ->toBe(0);
});

it('enforces consent in the action itself, not only at the request boundary', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardIp: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardIp: '10.44.0.3');
    remove_node_offline_probe($target);
    remove_node_role_fixture($target, RoleName::AppProd);

    expect(fn () => app(RemoveNodeAction::class)->execute($target, $caller, offline: true))
        ->toThrow(ResourceOperationException::class);

    expect($target->fresh())
        ->not
        ->toBeNull()
        ->and(NodeRole::query()->where('node_id', $target->id)->sole()->status)
        ->toBe(LifecycleStatus::Active);
});

function remove_node_offline_probe(Node $unreachable): void
{
    app()->instance(NodeReachabilityProbe::class, new RemoveNodeFakeReachability($unreachable->id));
}

function remove_node_reachable_probe(): void
{
    app()->instance(NodeReachabilityProbe::class, new RemoveNodeFakeReachability(null));
}

function remove_node_role_fixture(Node $node, RoleName $role): void
{
    NodeRole::query()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);
}

function remove_node_owned_process(Node $node): Process
{
    return $node->processes()->create([
        'name' => 'postgres',
        'runtime' => 'docker',
        'working_directory' => '/app',
        'runtime_config' => ['image' => 'postgres:18', 'command' => ['postgres']],
        'restart_policy' => 'unless-stopped',
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Active,
    ]);
}

function remove_node_record(string $name, string $wireguardIp): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => str_replace(
            search: '10.44.0.',
            replace: '192.0.2.',
            subject: $wireguardIp,
        ),
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => $wireguardIp,
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
}

final class RemoveNodeFakeDnsManager implements PrivateDnsManager
{
    public int $convergences = 0;

    public ?Throwable $failure = null;

    public function converge(?Node $pendingNode = null): void
    {
        $this->convergences++;

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }
}

final class RemoveNodeFakeMetricsAccessRevoker implements MetricsAccessRevoker
{
    public int $calls = 0;

    public ?Throwable $failure = null;

    public function revoke(): void
    {
        $this->calls++;

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }
}

final class RemoveNodeFakePeerProjection implements GatewayPeerProjectionManager
{
    /** @var list<int> */
    public array $removed = [];

    /** @var list<int> */
    public array $restored = [];

    public ?Throwable $removeFailure = null;

    public ?Throwable $restoreFailure = null;

    public function converge(Node $node): void {}

    public function remove(Node $node): void
    {
        $this->removed[] = $node->id;

        if ($this->removeFailure instanceof Throwable) {
            throw $this->removeFailure;
        }
    }

    public function restore(Node $node): void
    {
        $this->restored[] = $node->id;

        if ($this->restoreFailure instanceof Throwable) {
            throw $this->restoreFailure;
        }
    }
}

final class RemoveNodeFakeMetricsRuntime implements MetricsRuntimeLifecycle
{
    public function converge(Node $node, NodeRole $assignment): void {}

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

    public function health(Node $node, string $service): bool
    {
        return true;
    }
}

final class RemoveNodeUnreachableExporterRuntime implements MetricsCadvisorRuntime, MetricsExporterRuntime
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(
        private readonly string $unreachableNode,
    ) {}

    public function snapshot(Node $node, Node $metricsNode): MetricsExporterState
    {
        $this->events[] = "snapshot:{$node->name}";
        $this->guard($node);

        return new MetricsExporterState(null, false, UfwRuleOwnership::Missing);
    }

    public function converge(Node $node, Node $metricsNode): void
    {
        $this->events[] = "converge:{$node->name}";
        $this->guard($node);
    }

    public function remove(Node $node, Node $metricsNode): void
    {
        $this->events[] = "remove:{$node->name}";
        $this->guard($node);
    }

    public function restore(Node $node, Node $metricsNode, MetricsExporterState $state): void
    {
        $this->events[] = "restore:{$node->name}";
        $this->guard($node);
    }

    public function actual(Node $node, Node $metricsNode): string
    {
        return $node->name === $this->unreachableNode ? 'unknown' : 'active';
    }

    private function guard(Node $node): void
    {
        if ($node->name !== $this->unreachableNode) {
            return;
        }

        throw new ResourceOperationException(
            'metrics.exporter_configuration_inspection_failed',
            'The Metrics exporter configuration could not be inspected.',
            502,
        );
    }
}

final class RemoveNodeFakeReachability implements NodeReachabilityProbe
{
    public function __construct(
        private readonly ?int $unreachableNodeId,
    ) {}

    public function degradation(Node $node): ?ExporterDegradationReason
    {
        return $node->id === $this->unreachableNodeId ? ExporterDegradationReason::Unreachable : null;
    }
}

final class RemoveNodeFakeProcessRuntimeManager implements ProcessRuntimeManager
{
    /** @var list<int> */
    public array $removed = [];

    /** @var list<string> */
    public array $commands = [];

    public function assertCanStart(Process $process): void {}

    public function converge(Process $process): void
    {
        $this->commands[] = 'converge';
    }

    public function start(Process $process): void
    {
        $this->commands[] = 'start';
    }

    public function stop(Process $process): void
    {
        $this->commands[] = 'stop';
    }

    public function restart(Process $process): void
    {
        $this->commands[] = 'restart';
    }

    public function status(Process $process): string
    {
        return 'stopped';
    }

    public function logs(Process $process, int $lines): string
    {
        return '';
    }

    public function remove(Process $process): void
    {
        $this->commands[] = 'remove';
        $this->removed[] = $process->id;
    }
}

final class RemoveNodeCountingCleaner implements NodeRoleDependentCleaner
{
    public int $calls = 0;

    public function clean(NodeRoleDependencySet $dependencies): void
    {
        $this->calls++;
    }
}
