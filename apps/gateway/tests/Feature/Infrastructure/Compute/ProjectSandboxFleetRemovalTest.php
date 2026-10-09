<?php

declare(strict_types=1);

use App\Actions\Routes\CreateRouteAction;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxNetworkPolicy;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
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
use App\Domain\Routes\RouteStatus;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Infrastructure\Compute\ProjectSandboxFleetRemoval;
use App\Infrastructure\Compute\ProjectSandboxInstanceRemoval;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\Route;
use Tests\Support\IncusRuntimeWorkspace;
use Tests\Support\UpCloudRuntimeWorkspace;

it('refuses unrelated Node ownership and live workspace references before removal', function (string $fault): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $sandbox = $workspace->taskSandbox;
    match ($fault) {
        'node' => $workspace->node->forceFill(['compute_sandbox_id' => null])->save(),
        'role' => $workspace->node->roles()->create(['role' => 'app-prod', 'status' => 'active']),
        'workspace' => Instance::query()->create(['project_id' => $workspace->project_id, 'node_id' => $workspace->node_id, 'name' => 'foreign', 'checkout_path' => '/other']),
        'branch' => $workspace->update(['branch_override' => 'main']),
        'route intent' => $workspace->update(['task_workspace_routed' => true, 'app_overrides' => fixture_app_overrides('foreign')]),
    };

    expect(fn () => app(ProjectSandboxFleetRemoval::class)->assertRemovable($sandbox->fresh()))->toThrow(ComputeException::class);
    $this->assertModelExists($workspace);
    expect($sandbox->fresh()->desired_power)->toBe('running');
})->with(['node', 'role', 'workspace', 'branch', 'route intent']);

it('requires durable destruction intent and model revocation before detaching an owned workspace', function (): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $sandbox = $workspace->taskSandbox;

    expect(fn () => app(ProjectSandboxFleetRemoval::class)->remove($sandbox))->toThrow(ComputeException::class, 'revoke');
    $this->assertModelExists($workspace);
});

it('removes the exclusive workspace, native role and peer before hub policy, and retries a failed peer removal', function (string $provider, bool $fail, bool $preview): void {
    $workspace = $provider === 'incus' ? IncusRuntimeWorkspace::create() : UpCloudRuntimeWorkspace::create();
    $sandbox = $workspace->taskSandbox;
    $sandbox->forceFill(['desired_power' => 'destroyed', 'model_key' => null])->save();
    $node = $workspace->node;
    $route = null;
    if ($preview) {
        $workspace->project->update(['type' => 'laravel-app', 'apps' => fixture_apps('public', 'laravel-app')]);
        $workspace->update(['task_workspace_routed' => true]);
        $route = app(CreateRouteAction::class)->ensureForInstance($workspace, null);
        $route->update(['status' => RouteStatus::Active, 'sites_published' => true]);
        $workspace->update(['status' => InstanceState::Active, 'starting_commit' => str_repeat('a', 40)]);

    }
    $projection = \Pest\Laravel\mock(InstanceRemovalProjector::class);
    if ($preview) {
        $projection->shouldReceive('clearRouteTarget')->once()->andReturnUsing(function ($member) use ($workspace, $node, $route): string {
            test()->assertModelExists($workspace);
            test()->assertModelExists($node);
            expect($member->source_prepared_at)->not->toBeNull();
            expect($node->roles()->exists())->toBeTrue();
            $route->targets()->delete();
            $route->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);
            $route->delete();

            return 'deleted';
        });
    }
    $projection->shouldReceive('cleanupRuntime')->once();
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
        ->andReturnUsing(function () use ($workspace, $node, $route, $fail, &$attempt): void {
            test()->assertModelMissing($workspace);
            if ($route !== null) {
                test()->assertModelMissing($route);
            }
            expect($node->roles()->exists())->toBeFalse();
            if ($fail && ++$attempt === 1) {
                throw new RuntimeException('Peer projection unavailable');
            }
        });
    \Pest\Laravel\mock(SandboxNetworkPolicy::class)->shouldReceive('remove')->once()->andReturnUsing(function ($s) use ($node): void {
        test()->assertModelMissing($node);
        expect($s->node_id)->toBeNull();
    });
    $removal = app(ProjectSandboxFleetRemoval::class);
    if ($fail) {
        expect(fn () => $removal->remove($sandbox))->toThrow(NodeRemovalException::class);
        $this->assertModelExists($node);
        expect($sandbox->fresh()->provider)->toBe($provider);
        expect($sandbox->fresh()->enrollment)->not->toBeNull();
        expect($sandbox->fresh()->desired_power)->toBe('destroyed');
    }
    $removal->remove($sandbox->fresh());

    $this->assertModelMissing($workspace);
    $member = InstanceRemovalMember::query()->where('instance_id', $workspace->id)->sole();
    expect($member->removal->status->value)->toBe('completed');
    expect($member->source_identity)->toBe('sandbox:'.$sandbox->id);
    expect($member->finalization_receipt)->toStartWith('retained-in-sandbox:'.$sandbox->id.':');
    expect($member->row_deleted_at)->not->toBeNull();
    $this->assertModelMissing($node);
    expect($sandbox->fresh()->node_id)->toBeNull();
    expect($sandbox->fresh()->provider)->toBe($provider);
    expect($sandbox->fresh()->enrollment)->not->toBeNull();
})->with([
    'cloud source' => ['upcloud', false, false], 'local source' => ['incus', false, false],
    'cloud peer retry' => ['upcloud', true, false], 'local peer retry' => ['incus', true, false],
    'local preview' => ['incus', false, true], 'local preview peer retry' => ['incus', true, true],
]);

it('keeps the Route, Instance, and both fleet ownership records when preview withdrawal fails', function (): void {
    $workspace = IncusRuntimeWorkspace::create();
    $workspace->project->update(['type' => 'laravel-app', 'apps' => fixture_apps('public', 'laravel-app')]);
    $workspace->update(['task_workspace_routed' => true]);
    $route = app(CreateRouteAction::class)->ensureForInstance($workspace, null);
    $sandbox = $workspace->taskSandbox;
    $sandbox->forceFill(['desired_power' => 'destroyed', 'model_key' => null])->save();
    $projection = \Pest\Laravel\mock(InstanceRemovalProjector::class);
    $projection->shouldReceive('clearRouteTarget')->once()->andThrow(new RuntimeException('Private DNS unavailable'));
    \Pest\Laravel\mock(SandboxNetworkPolicy::class)->shouldNotReceive('remove');
    expect(fn () => app(ProjectSandboxFleetRemoval::class)->remove($sandbox))->toThrow(RuntimeException::class);
    $this->assertModelExists($route);
    $this->assertModelExists($workspace);
    $this->assertModelExists($workspace->node);
    expect($workspace->fresh()->status->value)->toBe('removing');
    expect($sandbox->fresh()->node_id)->toBe($workspace->node_id);
    expect($workspace->node->fresh()->compute_sandbox_id)->toBe($sandbox->id);
});

it('refuses changed private preview targets before cleanup changes power or revokes credentials', function (string $fault): void {
    $workspace = IncusRuntimeWorkspace::create();
    $workspace->project->update(['type' => 'laravel-app', 'apps' => fixture_apps('public', 'laravel-app')]);
    $workspace->update(['task_workspace_routed' => true]);
    $route = app(CreateRouteAction::class)->ensureForInstance($workspace, null);
    if ($fault === 'foreign target') {
        $route->targets()->delete();
        $route->delete();
        $route = Route::query()->create(['project_id' => $workspace->project_id,
            'cluster_id' => $workspace->node->cluster_id, 'domain' => 'foreign.dlf.test', 'provenance' => 'explicit',
            'publication' => 'private', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $workspace->id, 'position' => 0]);
    } elseif ($fault === 'domain') {
        $workspace->project->update(['slug' => 'foreign']);
    } else {
        $route->update(['publication' => 'public']);
    }
    expect(fn () => app(ProjectSandboxFleetRemoval::class)->assertRemovable($workspace->taskSandbox))->toThrow(ComputeException::class);
    expect($workspace->taskSandbox->fresh()->desired_power)->toBe('running');
    expect($workspace->taskSandbox->fresh()->model_key)->not->toBeNull();
    $this->assertModelExists($route);
})->with(['public', 'domain', 'foreign target']);

it('resumes partial active preview withdrawal from the same native journal without deleting source', function (): void {
    $workspace = IncusRuntimeWorkspace::create();
    $workspace->project->update(['type' => 'laravel-app', 'apps' => fixture_apps('public', 'laravel-app')]);
    $workspace->update(['task_workspace_routed' => true, 'starting_commit' => str_repeat('a', 40)]);
    $route = app(CreateRouteAction::class)->ensureForInstance($workspace, null);
    $route->update(['status' => RouteStatus::Active, 'sites_published' => true]);
    $workspace->update(['status' => InstanceState::Active]);
    $sandbox = $workspace->taskSandbox;
    $sandbox->forceFill(['desired_power' => 'destroyed', 'model_key' => null])->save();
    $runtime = \Pest\Laravel\mock(InstanceRemovalProjector::class);
    $runtime->shouldReceive('clearRouteTarget')->once()->andReturnUsing(function ($member) use ($route): string {
        expect($member->source_prepared_at)->not->toBeNull();
        $route->targets()->delete();
        $route->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);
        throw new RuntimeException('DNS unavailable after target withdrawal');
    });
    expect(fn () => app(ProjectSandboxFleetRemoval::class)->remove($sandbox))->toThrow(RuntimeException::class);
    $member = $workspace->removalMember()->sole();
    expect($member->removal->status->value)->toBe('failed');
    expect($member->route_cleared_at)->toBeNull();
    expect($route->fresh()->targets()->count())->toBe(0);
    app(ProjectSandboxFleetRemoval::class)->assertRemovable($sandbox->fresh());
    $runtime = \Pest\Laravel\mock(InstanceRemovalProjector::class);
    $runtime->shouldReceive('clearRouteTarget')->once()->andReturnUsing(function ($retried) use ($member, $route): string {
        expect($retried->id)->toBe($member->id);
        $route->delete();

        return 'deleted';
    });
    $runtime->shouldReceive('cleanupRuntime')->once();
    app(ProjectSandboxInstanceRemoval::class)->remove($workspace->fresh());
    test()->assertModelMissing($workspace);
    test()->assertModelMissing($route);
    expect($member->fresh()->removal->status->value)->toBe('completed');
    expect($member->fresh()->finalization_receipt)->toStartWith('retained-in-sandbox:');
    test()->assertModelExists($workspace->node);
    expect($sandbox->fresh()->node_id)->toBe($workspace->node_id);
});
