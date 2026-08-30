<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleDependentCleaner;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Infrastructure\Firewall\UfwRuleOwnership;
use App\Infrastructure\Metrics\MetricsExporterRuntime;
use App\Infrastructure\Metrics\MetricsExporterState;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\FirewallRule;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;

beforeEach(function (): void {
    $this->dns = new RemoveNodeFakeDnsManager;
    $this->peers = new RemoveNodeFakePeerProjection;
    app()->instance(PrivateDnsManager::class, $this->dns);
    app()->instance('App\\Domain\\WireGuard\\GatewayPeerProjectionManager', $this->peers);
});

it('retires Metrics exporter state before removing network projections', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
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
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertOk();

    expect($this->peers->removed)->toBe([$target->id]);
});

it('restores active Metrics selection when exporter retirement fails', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('retire')->once()->andThrow(new RuntimeException('private Metrics failure'));
    $metrics->shouldReceive('reconcile')->once();
    app()->instance(MetricsFleetReconciler::class, $metrics);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'unreachable', wireguardAddress: '10.44.0.3');
    $metricsNode = remove_node_record(name: 'metrics', wireguardAddress: '10.44.0.4');
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
    app()->instance(MetricsRuntimeLifecycle::class, new RemoveNodeFakeMetricsRuntime);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $metricsNode = remove_node_record(name: 'metrics', wireguardAddress: '10.44.0.4');
    $peer = remove_node_record(name: 'dead-peer', wireguardAddress: '10.44.0.5');
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
    app()->instance(MetricsExporterRuntime::class, new RemoveNodeUnreachableExporterRuntime('dead-peer'));
    app()->instance(MetricsRuntimeLifecycle::class, new RemoveNodeFakeMetricsRuntime);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertOk();

    expect($target->fresh())
        ->toBeNull()
        ->and(app(ExporterDegradationRepository::class)->get($peer->id))
        ->toBe(ExporterDegradationReason::Unreachable);
});

it('removes only the target WireGuard peer before reconciling DNS', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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

it('returns 502 and retains active state when WireGuard projection fails', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    $this->peers->removeFailure = new RuntimeException('private projection detail');
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('retire')->once();
    $metrics->shouldReceive('reconcile')->once();
    app()->instance(MetricsFleetReconciler::class, $metrics);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    $this->dns->failure = new RuntimeException('private DNS detail');
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('retire')->once();
    $metrics->shouldReceive('reconcile')->once();
    app()->instance(MetricsFleetReconciler::class, $metrics);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    $this->dns->failure = new RuntimeException('private DNS detail');
    $this->peers->restoreFailure = new RuntimeException('private rollback detail');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'persistence-failure', wireguardAddress: '10.44.0.3');
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
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $requestId = '47783d46-e420-42f6-868d-31dadf54105c';

    $response = $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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
        ->toBe(1);

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
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $caller->accessibleNodes()->attach($caller);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    NodeRole::query()->create([
        'node_id' => $target->id,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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
})->with(RoleName::cases());

it('returns 409 when the target still owns an instance', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
    ]);
    Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $target->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/var/www/acme/production',
        'hostname' => 'acme.example.com',
        'certificate_mode' => 'acme',
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['offline' => false])
        ->assertConflict()
        ->assertJsonPath('error.code', 'node.has_instances');

    expect($target->fresh())
        ->not
        ->toBeNull()
        ->and($this->dns->convergences)
        ->toBe(0);
});

it('returns 409 when the target still owns a firewall rule', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
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
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    $target->update(['wireguard_public_key' => 'TARGET_PUBLIC_KEY']);
    remove_node_offline_probe($target);
    remove_node_role_fixture($target, RoleName::AppProd);
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('reconcile')->atLeast()->once();
    $metrics->shouldReceive('retire')->once();
    app()->instance(MetricsFleetReconciler::class, $metrics);

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['force' => true, 'offline' => true]);

    $response
        ->assertOk()
        ->assertJsonPath('data.removed', true)
        ->assertJsonPath('data.degradation', 'unreachable')
        ->assertJsonPath('data.roles_shed', ['app-prod'])
        ->assertJsonPath('data.wireguard_peer_removed', true)
        ->assertJsonPath(
            'data.follow_up',
            'Run the node-local Metrics cleanup on the node once it boots, or discard the node.',
        );

    expect($response->json('data.retained_on_node'))
        ->toContain('Caddy site configuration and certificates for the app-prod role')
        ->toContain('Orbit firewall rules for the app-prod role')
        ->and($target->fresh())
        ->toBeNull()
        ->and(NodeRole::query()->where('node_id', $target->id)->exists())
        ->toBeFalse()
        ->and(Instance::query()->where('node_id', $target->id)->exists())
        ->toBeFalse()
        ->and($this->peers->removed)
        ->toBe([$target->id]);
});

it('refuses an unreachable node without the offline claim and names the flag', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    remove_node_offline_probe($target);
    remove_node_role_fixture($target, RoleName::AppProd);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    remove_node_reachable_probe();
    remove_node_role_fixture($target, RoleName::AppProd);
    $cleaner = new RemoveNodeCountingCleaner;
    app()->instance(NodeRoleDependentCleaner::class, $cleaner);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    remove_node_offline_probe($target);
    NodeRole::query()->create([
        'node_id' => $target->id,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->deleteJson("/api/v1/nodes/{$target->id}", ['force' => true, 'offline' => 'yes'])
        ->assertStatus(422);

    expect($target->fresh())->not->toBeNull();
});

it('refuses to shed roles from an unreachable node without consent', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    $caller->accessibleNodes()->attach($target);
    remove_node_offline_probe($target);
    remove_node_role_fixture($target, RoleName::AppProd);

    // Offline removal deletes the node's instances, workspaces and processes.
    // Before it existed this call was refused outright, so the widened blast
    // radius must not be reachable on the claim alone.
    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
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
        ->and(Instance::query()->where('node_id', $target->id)->exists())
        ->toBeTrue()
        ->and($this->dns->convergences)
        ->toBe(0);
});

it('enforces consent in the action itself, not only at the request boundary', function (): void {
    $caller = remove_node_record(name: 'operator', wireguardAddress: '10.44.0.2');
    $target = remove_node_record(name: 'retired', wireguardAddress: '10.44.0.3');
    remove_node_offline_probe($target);
    remove_node_role_fixture($target, RoleName::AppProd);

    expect(fn () => app(App\Actions\Nodes\RemoveNodeAction::class)->execute($target, $caller, offline: true))
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
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
    ]);
    Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/var/www/acme/production',
        'hostname' => 'acme.example.com',
        'certificate_mode' => 'acme',
        'status' => LifecycleStatus::Active,
    ]);
}

function remove_node_record(string $name, string $wireguardAddress): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => str_replace(
            search: '10.44.0.',
            replace: '192.0.2.',
            subject: $wireguardAddress,
        ),
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_address' => $wireguardAddress,
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

/** @mago-expect lint:single-class-per-file Test-local fakes keep projection state visible to this API suite. */
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

/** @mago-expect lint:single-class-per-file Test-local fakes keep projection state visible to this API suite. */
final class RemoveNodeFakeMetricsRuntime implements MetricsRuntimeLifecycle
{
    public function converge(Node $node, NodeRole $assignment): void {}

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

    public function health(Node $node, string $service): bool
    {
        return true;
    }
}

/** @mago-expect lint:single-class-per-file Test-local fakes keep projection state visible to this API suite. */
final class RemoveNodeUnreachableExporterRuntime implements MetricsExporterRuntime
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

/** @mago-expect lint:single-class-per-file Test-local fakes keep projection state visible to this API suite. */
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

/** @mago-expect lint:single-class-per-file Test-local fakes keep projection state visible to this API suite. */
final class RemoveNodeCountingCleaner implements NodeRoleDependentCleaner
{
    public int $calls = 0;

    public function clean(NodeRoleDependencySet $dependencies): void
    {
        $this->calls++;
    }
}
