<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxNetworkPolicy;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRemovalException;
use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleDependentCleaner;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Infrastructure\Compute\UpCloudSandboxFleetRemoval;
use App\Models\Instance;
use Tests\Support\UpCloudRuntimeWorkspace;

it('refuses unrelated Node ownership and live workspace references before removal', function (string $fault): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $sandbox = $workspace->taskSandbox;
    match ($fault) {
        'node' => $workspace->node->forceFill(['compute_sandbox_id' => null])->save(),
        'role' => $workspace->node->roles()->create(['role' => 'app-prod', 'status' => 'active']),
        'workspace' => Instance::query()->create(['project_id' => $workspace->project_id, 'node_id' => $workspace->node_id, 'name' => 'foreign', 'checkout_path' => '/other']),
        'branch' => $workspace->update(['branch_override' => 'main']),
        'route intent' => $workspace->update(['task_workspace_routed' => true]),
    };

    expect(fn () => app(UpCloudSandboxFleetRemoval::class)->assertRemovable($sandbox->fresh()))->toThrow(ComputeException::class);
    $this->assertModelExists($workspace);
    expect($sandbox->fresh()->desired_power)->toBe('running');
})->with(['node', 'role', 'workspace', 'branch', 'route intent']);

it('requires durable destruction intent and model revocation before detaching an owned workspace', function (): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $sandbox = $workspace->taskSandbox;

    expect(fn () => app(UpCloudSandboxFleetRemoval::class)->remove($sandbox))->toThrow(ComputeException::class, 'revoke');
    $this->assertModelExists($workspace);
});

it('removes the exclusive workspace, native role and peer before hub policy, and retries a failed peer removal', function (bool $fail): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $sandbox = $workspace->taskSandbox;
    $sandbox->forceFill(['desired_power' => 'destroyed', 'model_key' => null])->save();
    $node = $workspace->node;
    $node->update(['wireguard_public_key' => 'proof-peer']);
    $times = $fail ? 2 : 1;
    \Pest\Laravel\mock(NodeReachabilityProbe::class)->shouldReceive('degradation')->andReturnNull();
    \Pest\Laravel\mock(NodeRoleDependencyInspector::class)->shouldReceive('inspect')->andReturn(new NodeRoleDependencySet([], [], [], []));
    \Pest\Laravel\mock(NodeRoleDependentCleaner::class)->shouldReceive('clean')->once();
    \Pest\Laravel\mock(RoleBaselineConverger::class)->shouldReceive('remove')->once();
    \Pest\Laravel\mock(NodeRoleFirewallManager::class)->shouldReceive('restorePublicSsh')->once();
    \Pest\Laravel\mock(MetricsAccessRevoker::class)->shouldReceive('revoke')->times($times);
    $metrics = \Pest\Laravel\mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('retire')->times($times);
    if ($fail) {
        $metrics->shouldReceive('reconcile')->once();
    }
    \Pest\Laravel\mock(NodeAgentRuntime::class)->shouldReceive('remove')->times($times);
    \Pest\Laravel\mock(PrivateDnsManager::class)->shouldReceive('converge')->once();
    \Pest\Laravel\mock(RouterLanIngressReconciler::class)->shouldReceive('prune')->once();
    $attempt = 0;
    \Pest\Laravel\mock(GatewayPeerProjectionManager::class)->shouldReceive('remove')->times($times)
        ->andReturnUsing(function () use ($workspace, $node, $fail, &$attempt): void {
            test()->assertModelMissing($workspace);
            expect($node->roles()->exists())->toBeFalse();
            if ($fail && ++$attempt === 1) {
                throw new RuntimeException('Peer projection unavailable');
            }
        });
    \Pest\Laravel\mock(SandboxNetworkPolicy::class)->shouldReceive('remove')->once()->andReturnUsing(function ($s) use ($node): void {
        test()->assertModelMissing($node);
        expect($s->node_id)->toBeNull();
    });
    $removal = app(UpCloudSandboxFleetRemoval::class);
    if ($fail) {
        expect(fn () => $removal->remove($sandbox))->toThrow(NodeRemovalException::class);
        $this->assertModelExists($node);
        expect($sandbox->fresh()->server_id)->not->toBeNull();
        expect($sandbox->fresh()->desired_power)->toBe('destroyed');
    }
    $removal->remove($sandbox->fresh());

    $this->assertModelMissing($workspace);
    $this->assertModelMissing($node);
    expect($sandbox->fresh()->node_id)->toBeNull();
    expect($sandbox->fresh()->server_id)->not->toBeNull();
})->with([false, true]);
