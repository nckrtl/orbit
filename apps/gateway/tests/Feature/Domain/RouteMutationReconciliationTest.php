<?php

declare(strict_types=1);

use App\Actions\Clusters\AttachClusterNodeAction;
use App\Actions\Clusters\DetachClusterNodeAction;
use App\Actions\Clusters\SetClusterRouterAction;
use App\Actions\Clusters\UpdateClusterAction;
use App\Actions\Nodes\ProvisionNodeAction;
use App\Actions\Routes\ClearRouteTargetAction;
use App\Actions\Routes\CreateRouteAction;
use App\Actions\Routes\RemoveRouteAction;
use App\Actions\Routes\SetRouteTargetAction;
use App\Actions\Routes\UpdateRouteAction;
use App\Data\Clusters\UpdateClusterData;
use App\Data\Nodes\ProvisionNodeData;
use App\Data\Routes\CreateRouteData;
use App\Data\Routes\UpdateRouteData;
use App\Domain\AppDev\AppDevTldConverger;
use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RouteMutationReconciler;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;
use Tests\Support\FakeClusterRouterDnsSelectionReconciler;
use Tests\Support\FakeToolManagerMaterializer;

beforeEach(function (): void {
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->node = reconciliation_node('dev', 'dev.test');
    $this->target = reconciliation_instance($this->orbitApp, $this->node, 'feature');
});

it('converges an eligible active explicit development Route domain', function (): void {
    $this->target->update(['source_is_laravel' => false, 'provisioning_step' => 'active']);
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $this->orbitApp->id,
        domain: 'active.example.test',
        publication: RoutePublication::Private,
        appInstanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => RouteStatus::Active]);
    $projector = Mockery::mock(RouteDomainProjector::class);

    foreach ([
        'prepareWorkloadCertificate',
        'prepareWorkloadCaddy',
        'prepareRouterCertificate',
        'prepareFirewallPolicy',
        'prepareRouterCaddy',
        'publishDns',
        'cleanup',
    ] as $method) {
        $projector->shouldReceive($method)->once();
    }
    $projector->shouldReceive('verifyWorkload')->twice();

    app()->instance(RouteDomainProjector::class, $projector);
    app()->instance(
        DevelopmentAppInstanceConfigurator::class,
        Mockery::mock(DevelopmentAppInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteMutationProjectionOwner);

    $updated = app(UpdateRouteAction::class)->execute(
        $route,
        new UpdateRouteData(true, 'next.example.test', false, null),
    );

    expect($updated->domain)
        ->toBe('next.example.test')
        ->and($updated->status)
        ->toBe(RouteStatus::Active)
        ->and($updated->id)
        ->not->toBe($route->id)
        ->and($updated->replaced_by_route_id)
        ->toBeNull();
});

it('reports association conflicts before active Route reconciliation refusals', function (): void {
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $this->orbitApp->id,
        domain: 'active.example.test',
        publication: RoutePublication::Private,
        appInstanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => RouteStatus::Active]);
    $replacement = reconciliation_instance(
        $this->orbitApp,
        reconciliation_node('replacement', 'replacement.test'),
        'replacement',
    );
    $before = $route->fresh(['targets'])->toArray();

    foreach ([
        fn () => app(SetRouteTargetAction::class)->execute($route, $replacement->id),
        fn () => app(ClearRouteTargetAction::class)->execute($route),
        fn () => app(RemoveRouteAction::class)->execute($route),
    ] as $mutation) {
        expect($mutation)->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('route.target_conflict');
        });
        expect($route->fresh(['targets'])->toArray())->toBe($before);
    }
});

it('retains reconciliation refusals for active Route changes without association conflicts', function (): void {
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $this->orbitApp->id,
        domain: 'active.example.test',
        publication: RoutePublication::Private,
        appInstanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => RouteStatus::Active]);
    $this->target->update(['status' => AppInstanceState::Reserved]);
    $replacement = reconciliation_instance(
        $this->orbitApp,
        reconciliation_node('replacement', 'replacement.test'),
        'replacement',
    );
    $before = $route->fresh(['targets'])->toArray();

    foreach ([
        fn () => app(UpdateRouteAction::class)->execute(
            $route,
            new UpdateRouteData(true, 'changed.example.test', false, null),
        ),
        fn () => app(SetRouteTargetAction::class)->execute($route, $replacement->id),
        fn () => app(RemoveRouteAction::class)->execute($route),
    ] as $mutation) {
        expect($mutation)->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('route.reconciliation_required');
        });
        expect($route->fresh(['targets'])->toArray())->toBe($before);
    }
});

it('retains domain reconciliation refusals for generated Routes before projection', function (): void {
    $this->target->update(['source_is_laravel' => false, 'provisioning_step' => 'active']);
    $generated = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $generated->update(['status' => RouteStatus::Active]);

    app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class));
    app()->instance(
        DevelopmentAppInstanceConfigurator::class,
        Mockery::mock(DevelopmentAppInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteMutationProjectionOwner);

    $before = $generated->fresh(['targets'])->toArray();

    expect(fn () => app(UpdateRouteAction::class)->execute(
        $generated,
        new UpdateRouteData(true, "next-{$generated->id}.example.test", false, null),
    ))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('route.reconciliation_required');
    });

    expect($generated->fresh(['targets'])->toArray())->toBe($before);
});

it('retains Node, Cluster, and Router reconciliation refusals before dependent state changes', function (): void {
    $standalone = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $standalone->update(['status' => RouteStatus::Active]);
    $cluster = reconciliation_active_cluster('active-refusal', 'cluster.test');
    $standaloneBefore = $standalone->fresh(['targets'])->toArray();
    $nodeBefore = $this->node->fresh()->toArray();

    expect(fn () => app(AttachClusterNodeAction::class)->execute($cluster, $this->node))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('route.reconciliation_required');
        });
    expect($standalone->fresh(['targets'])->toArray())
        ->toBe($standaloneBefore)
        ->and($this->node->fresh()->toArray())
        ->toBe($nodeBefore)
        ->and(array_column(route_mutation_dns_reconciler()->events, 'phase'))
        ->toBe(['expand', 'prune']);

    $workload = reconciliation_node('cluster-workload-refusal', null);
    $workload->update(['cluster_id' => $cluster->id]);
    $clusterTarget = reconciliation_instance($this->orbitApp, $workload, 'cluster-target');
    $clusterRoute = app(CreateRouteAction::class)->ensureForAppInstance($clusterTarget, null);
    $clusterRoute->update(['status' => RouteStatus::Active]);
    $clusterBefore = $cluster->fresh()->toArray();
    $clusterRouteBefore = $clusterRoute->fresh(['targets'])->toArray();

    expect(fn () => app(UpdateClusterAction::class)->execute(
        $cluster,
        reconciliation_update(tldProvided: true, tld: 'next-cluster.test'),
    ))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('route.reconciliation_required');
    });
    expect($cluster->fresh()->toArray())
        ->toBe($clusterBefore)
        ->and($clusterRoute->fresh(['targets'])->toArray())
        ->toBe($clusterRouteBefore)
        ->and(array_column(route_mutation_dns_reconciler()->events, 'phase'))
        ->toContain('expand')
        ->toContain('prune');

    $replacement = reconciliation_node('router-replacement-refusal', null);
    $replacement->update(['cluster_id' => $cluster->id]);
    $assignmentsBefore = NodeRole::query()
        ->where('cluster_id', $cluster->id)
        ->get()
        ->map
        ->getAttributes()
        ->all();

    expect(fn () => app(SetClusterRouterAction::class)->execute($cluster, $replacement))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('route.reconciliation_required');
        });
    expect(
        NodeRole::query()
            ->where('cluster_id', $cluster->id)
            ->get()
            ->map
            ->getAttributes()
            ->all(),
    )
        ->toBe($assignmentsBefore);
});

it('atomically reconciles attach, activation, TLD changes, deactivation, and detach', function (): void {
    $route = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $cluster = Cluster::query()->create([
        'name' => 'routing',
        'state' => ClusterState::Inactive,
        'tld' => 'cluster.test',
    ]);
    $router = reconciliation_node('router', null);
    $router->update(['cluster_id' => $cluster->id]);
    $router
        ->roles()
        ->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);

    app(AttachClusterNodeAction::class)->execute($cluster, $this->node);
    expect($route->refresh()->node_id)->toBe($this->node->id);

    app(UpdateClusterAction::class)->execute($cluster, reconciliation_update(state: ClusterState::Active));
    $route = reconciliation_route_by_domain('feature.acme.dev.test');
    expect($route->cluster_id)
        ->toBe($cluster->id)
        ->and($route->status->value)
        ->toBe('pending');

    $this->node->update(['tld' => null]);
    app(UpdateClusterAction::class)->execute($cluster, reconciliation_update(tldProvided: true, tld: 'next.test'));
    $route = reconciliation_route_by_domain('feature.acme.next.test');

    $this->node->update(['tld' => 'node.test']);
    app(UpdateClusterAction::class)->execute($cluster, reconciliation_update(state: ClusterState::Inactive));
    $route = reconciliation_route_by_domain('feature.acme.node.test');
    expect($route->node_id)->toBe($this->node->id);

    app(DetachClusterNodeAction::class)->execute($cluster, $this->node);
    expect($this->node->refresh()->cluster_id)->toBeNull()->and($route->refresh()->node_id)->toBe($this->node->id);
});

it('bypasses Route reconciliation when a Cluster patch leaves placement inputs unchanged', function (): void {
    $unrelatedNode = reconciliation_node('unrelated', 'unrelated.test');
    $unrelatedTarget = reconciliation_instance($this->orbitApp, $unrelatedNode, 'unrelated');
    $unrelatedTarget->update(['status' => AppInstanceState::CheckoutPrepared]);
    $unrelatedRoute = reconciliation_route(
        $this->orbitApp,
        'unrelated.acme.unrelated.test',
        node: $unrelatedNode,
        basis: $unrelatedNode,
    );
    $unrelatedRoute->targets()->create(['app_instance_id' => $unrelatedTarget->id, 'position' => 0]);
    $unrelatedRoute->update([
        'status' => RouteStatus::Failed,
        'failed_step' => 'provisioning',
        'error_code' => 'instance.provisioning_failed',
    ]);
    $before = $unrelatedRoute->fresh(['targets'])->toArray();
    $cluster = Cluster::query()->create([
        'name' => 'routing',
        'state' => ClusterState::Inactive,
        'tld' => 'cluster.test',
    ]);

    $renamed = app(UpdateClusterAction::class)->execute($cluster, new UpdateClusterData(
        nameProvided: true,
        name: 'renamed',
        tldProvided: false,
        tld: null,
        stateProvided: false,
        state: null,
    ));
    $unchanged = app(UpdateClusterAction::class)->execute($renamed, new UpdateClusterData(
        nameProvided: false,
        name: null,
        tldProvided: true,
        tld: 'cluster.test',
        stateProvided: true,
        state: ClusterState::Inactive,
    ));

    expect($unchanged->name)
        ->toBe('renamed')
        ->and($unrelatedRoute->fresh(['targets'])->toArray())
        ->toBe($before);
});

it('hydrates and reconciles the complete affected Route dependency closure', function (): void {
    $targeted = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $targetlessDirect = reconciliation_route($this->orbitApp, 'direct.example.test', node: $this->node);
    $retainedTarget = reconciliation_instance($this->orbitApp, $this->node, 'retained');
    $retained = app(CreateRouteAction::class)->ensureForAppInstance($retainedTarget, null);
    $retainedTarget->update(['status' => AppInstanceState::Reserved]);
    app(ClearRouteTargetAction::class)->execute($retained);

    $cluster = reconciliation_active_cluster('production', 'cluster.test');
    $firstNode = reconciliation_node('production-one', null);
    $secondNode = reconciliation_node('production-two', null);
    foreach ([$firstNode, $secondNode] as $node) {
        $node->update(['cluster_id' => $cluster->id]);
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    }
    $firstTarget = reconciliation_instance($this->orbitApp, $firstNode, 'production-one');
    $secondTarget = reconciliation_instance($this->orbitApp, $secondNode, 'production-two');
    $firstTarget->update(['environment' => 'production', 'status' => AppInstanceState::SourceResolved]);
    $secondTarget->update(['environment' => 'production', 'status' => AppInstanceState::SourceResolved]);
    $multiTarget = reconciliation_route($this->orbitApp, 'production.example.test', cluster: $cluster);
    $multiTarget->targets()->create(['app_instance_id' => $firstTarget->id, 'position' => 0]);
    $multiTarget->targets()->create(['app_instance_id' => $secondTarget->id, 'position' => 1]);
    $multiTarget->update(['status' => RouteStatus::Active]);
    $firstTarget->update(['status' => AppInstanceState::Active]);
    $secondTarget->update(['status' => AppInstanceState::Active]);
    $targetlessCluster = reconciliation_route($this->orbitApp, 'targetless.example.test', cluster: $cluster);

    $unrelatedNode = reconciliation_node('unrelated-closure', 'unrelated.test');
    $unrelatedTarget = reconciliation_instance($this->orbitApp, $unrelatedNode, 'failed');
    $unrelatedTarget->update(['status' => AppInstanceState::CheckoutPrepared]);
    $unrelated = reconciliation_route(
        $this->orbitApp,
        'failed.acme.unrelated.test',
        node: $unrelatedNode,
        basis: $unrelatedNode,
    );
    $unrelated->targets()->create(['app_instance_id' => $unrelatedTarget->id, 'position' => 0]);
    $unrelated->update([
        'status' => RouteStatus::Failed,
        'failed_step' => 'provisioning',
        'error_code' => 'instance.provisioning_failed',
    ]);
    $unrelatedBefore = $unrelated->fresh(['targets'])->toArray();
    $hydrated = [];
    Route::retrieved(static function (Route $route) use (&$hydrated): void {
        $hydrated[] = $route->id;
    });

    app(RouteMutationReconciler::class)->reconcile(
        nodeOverrides: [$this->node->id => ['tld' => 'next.test']],
        clusterOverrides: [$cluster->id => ['tld' => 'next-cluster.test']],
    );

    sort($hydrated);
    $expected = [$targeted->id, $targetlessDirect->id, $retained->id, $multiTarget->id, $targetlessCluster->id];
    sort($expected);
    expect($hydrated)
        ->toBe($expected)
        ->and(reconciliation_route_by_domain('feature.acme.next.test')->id)
        ->not->toBe($targeted->id)
        ->and(reconciliation_route_by_domain('retained.acme.next.test')->id)
        ->not->toBe($retained->id)
        ->and($multiTarget->targets()->pluck('position')->all())
        ->toBe([0, 1])
        ->and($targetlessCluster->refresh()->cluster_id)
        ->toBe($cluster->id)
        ->and($unrelated->fresh(['targets'])->toArray())
        ->toBe($unrelatedBefore);
});

it('uses provisioning baseline overrides to select retained generated Routes', function (): void {
    $route = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $this->target->update(['status' => AppInstanceState::Reserved]);
    app(ClearRouteTargetAction::class)->execute($route);
    $this->node->update(['tld' => 'next.test']);

    app(RouteMutationReconciler::class)->reconcile(baselineNodeOverrides: [
        $this->node->id => ['tld' => 'dev.test'],
    ]);

    $replaced = reconciliation_route_by_domain('feature.acme.next.test');

    expect($replaced->id)
        ->not->toBe($route->id)
        ->and($replaced->generation_basis_node_id)
        ->toBe($this->node->id);
});

it('rejects a proposed domain owned by an unaffected Route before any write', function (): void {
    $affected = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $unaffectedNode = reconciliation_node('unaffected-owner', 'owner.test');
    $unaffected = reconciliation_route(
        $this->orbitApp,
        'feature.acme.next.test',
        node: $unaffectedNode,
    );
    $affectedBefore = $affected->fresh()->toArray();
    $unaffectedBefore = $unaffected->fresh()->toArray();

    expect(fn () => app(RouteMutationReconciler::class)->reconcile(nodeOverrides: [
        $this->node->id => ['tld' => 'next.test'],
    ]))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('route.domain_conflict');
    });

    expect($affected->fresh()->toArray())
        ->toBe($affectedBefore)
        ->and($unaffected->fresh()->toArray())
        ->toBe($unaffectedBefore);
});

it('keeps affected Route hydration bounded as unrelated graph state grows', function (): void {
    $affected = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $hydrated = [];
    Route::retrieved(static function (Route $route) use (&$hydrated): void {
        $hydrated[] = $route->id;
    });

    app(RouteMutationReconciler::class)->reconcile(nodeOverrides: [
        $this->node->id => ['tld' => 'first.test'],
    ]);
    $beforeGrowth = $hydrated;
    $afterFirst = reconciliation_route_by_domain('feature.acme.first.test');

    foreach (range(1, 12) as $index) {
        $node = reconciliation_node("growth-{$index}", "growth-{$index}.test");
        reconciliation_route($this->orbitApp, "growth-{$index}.example.test", node: $node);
    }
    $hydrated = [];

    app(RouteMutationReconciler::class)->reconcile(nodeOverrides: [
        $this->node->id => ['tld' => 'second.test'],
    ]);

    expect($beforeGrowth)
        ->toBe([$affected->id])
        ->and($hydrated)
        ->toBe([$afterFirst->id]);
});

it('reconciles a zero-target generated Route from its retained basis', function (): void {
    $cluster = reconciliation_active_cluster('routing', 'old.test');
    $this->node->update(['cluster_id' => $cluster->id, 'tld' => null]);
    $route = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $this->target->update(['status' => AppInstanceState::Reserved]);
    app(ClearRouteTargetAction::class)->execute($route);
    $owner = new RouteReconciliationClusterRouterOperationLock;
    app()->instance(ClusterRouterOperationLock::class, $owner);
    Route::updating(static function (Route $updating) use ($owner): void {
        expect($owner->active)->toBeTrue();
    });

    app(UpdateClusterAction::class)->execute($cluster, reconciliation_update(tldProvided: true, tld: 'new.test'));
    $replaced = reconciliation_route_by_domain('feature.acme.new.test');

    expect($replaced->id)
        ->not->toBe($route->id)
        ->and($replaced->generation_basis_node_id)
        ->toBe($this->node->id)
        ->and($replaced->targets()->count())
        ->toBe(0)
        ->and($replaced->status->value)
        ->toBe('pending')
        ->and($owner->clusterIds)
        ->toBe([$cluster->id]);
});

it('refuses an invalid proposal and preserves Route, Cluster, and membership state', function (): void {
    $cluster = Cluster::query()->create([
        'name' => 'routing',
        'state' => ClusterState::Inactive,
        'tld' => 'cluster.test',
    ]);
    app(AttachClusterNodeAction::class)->execute($cluster, $this->node);
    $route = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $routeBefore = $route->fresh(['targets'])->toArray();
    $clusterBefore = $cluster->fresh()->toArray();

    expect(fn () => app(UpdateClusterAction::class)->execute(
        $cluster,
        reconciliation_update(state: ClusterState::Active),
    ))
        ->toThrow(ResourceOperationException::class, 'requires one active Router');

    expect($route->fresh(['targets'])->toArray())
        ->toBe($routeBefore)
        ->and($cluster->fresh()->toArray())
        ->toBe($clusterBefore)
        ->and($this->node->refresh()->cluster_id)
        ->toBe($cluster->id);
});

it('requires an effective TLD when deactivation would strand a generated basis', function (): void {
    $cluster = reconciliation_active_cluster('routing', 'cluster.test');
    $this->node->update(['cluster_id' => $cluster->id, 'tld' => null]);
    $route = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $before = $route->fresh(['targets'])->toArray();

    expect(fn () => app(UpdateClusterAction::class)->execute(
        $cluster,
        reconciliation_update(state: ClusterState::Inactive),
    ))
        ->toThrow(ResourceOperationException::class, 'requires a Node TLD or active Cluster TLD');

    expect($route->fresh(['targets'])->toArray())
        ->toBe($before)
        ->and($cluster->refresh()->state)
        ->toBe(ClusterState::Active);
});

it('reconciles a retained generated Route and Node TLD before remote provisioning', function (): void {
    $this->node->update(['ssh_host_fingerprint' => 'SHA256:pinned']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $route = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $this->target->update(['status' => AppInstanceState::Reserved]);
    app(ClearRouteTargetAction::class)->execute($route);
    $this->target->delete();
    $observed = [];
    bind_route_reconciliation_provisioning(function (Node $node) use (&$observed): void {
        $current = reconciliation_route_by_domain('feature.acme.new.test');
        $observed = [
            'node_tld' => $node->fresh()->tld,
            'node_status' => $node->fresh()->status,
            'route_domain' => $current->domain,
            'route_status' => $current->status,
        ];
    });

    app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: $this->node->name,
        publicSshHost: $this->node->public_ssh_host,
        tldProvided: true,
        tld: 'new.test',
    ));
    $replaced = reconciliation_route_by_domain('feature.acme.new.test');

    expect($observed)
        ->toBe([
            'node_tld' => 'new.test',
            'node_status' => LifecycleStatus::Provisioning,
            'route_domain' => 'feature.acme.new.test',
            'route_status' => RouteStatus::Pending,
        ])
        ->and($replaced->only(['domain', 'generation_basis_node_id', 'failed_step', 'error_code']))
        ->toBe([
            'domain' => 'feature.acme.new.test',
            'generation_basis_node_id' => $this->node->id,
            'failed_step' => null,
            'error_code' => null,
        ]);
});

it('preserves a legacy default domain and source during Route-only reconciliation', function (): void {
    $this->target->update([
        'name' => 'main',
        'checkout_path' => '/srv/acme/main',
        'branch' => 'main',
        'migration_required' => true,
    ]);
    $route = reconciliation_route(
        $this->orbitApp,
        'acme.dev.test',
        node: $this->node,
        basis: $this->node,
    );
    $route->targets()->create(['app_instance_id' => $this->target->id, 'position' => 0]);
    $cluster = Cluster::query()->create([
        'name' => 'legacy-routing',
        'state' => ClusterState::Inactive,
        'tld' => 'cluster.test',
    ]);
    $router = reconciliation_node('legacy-router', null);
    $router->update(['cluster_id' => $cluster->id]);
    $router
        ->roles()
        ->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);
    app(AttachClusterNodeAction::class)->execute($cluster, $this->node);
    $sourceBefore = $this->target
        ->fresh()
        ->only([
            'name',
            'source_layout',
            'checkout_path',
            'root',
            'branch',
            'branch_override',
            'migration_required',
            'starting_commit',
        ]);
    $routeTargetBefore = $route->targets()->firstOrFail()->getAttributes();

    app(UpdateClusterAction::class)->execute($cluster, reconciliation_update(state: ClusterState::Active));

    expect($this->target->fresh()->only(array_keys($sourceBefore)))
        ->toBe($sourceBefore)
        ->and($route->refresh()->domain)
        ->toBe('acme.dev.test')
        ->and($route->cluster_id)
        ->toBe($cluster->id)
        ->and($route->targets()->firstOrFail()->getAttributes())
        ->toBe($routeTargetBefore);
});

it('preserves Node and Route state when the last app-dev TLD has no active fallback', function (): void {
    $this->node->update(['ssh_host_fingerprint' => 'SHA256:pinned']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $route = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $this->target->update(['status' => AppInstanceState::Reserved]);
    app(ClearRouteTargetAction::class)->execute($route);
    $this->target->delete();
    $nodeBefore = $this->node->fresh()->getAttributes();
    $routeBefore = $route->fresh(['targets'])->toArray();
    bind_route_reconciliation_provisioning();

    expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: $this->node->name,
        publicSshHost: $this->node->public_ssh_host,
        tldProvided: true,
        tld: null,
    )))
        ->toThrow(ResourceOperationException::class, 'An app-dev TLD is required');

    expect($this->node->fresh()->getAttributes())
        ->toBe($nodeBefore)
        ->and($route->fresh(['targets'])->toArray())
        ->toBe($routeBefore);
});

it('uses the active Cluster TLD when the retained basis Node TLD is cleared', function (): void {
    $cluster = reconciliation_active_cluster('fallback', 'cluster.test');
    $this->node->update([
        'cluster_id' => $cluster->id,
        'ssh_host_fingerprint' => 'SHA256:pinned',
    ]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $route = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $this->target->update(['status' => AppInstanceState::Reserved]);
    app(ClearRouteTargetAction::class)->execute($route);
    $this->target->delete();
    bind_route_reconciliation_provisioning();

    app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: $this->node->name,
        publicSshHost: $this->node->public_ssh_host,
        tldProvided: true,
        tld: null,
    ));

    $replaced = reconciliation_route_by_domain('feature.acme.cluster.test');

    expect($this->node->refresh()->tld)
        ->toBeNull()
        ->and($replaced->id)
        ->not->toBe($route->id)
        ->and($replaced->cluster_id)
        ->toBe($cluster->id)
        ->and($replaced->status->value)
        ->toBe('pending');
});

it('keeps an explicit app-prod Route valid when its Node has no TLD', function (): void {
    $this->node->update(['ssh_host_fingerprint' => 'SHA256:pinned']);
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $this->target->delete();
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $this->orbitApp->id,
        domain: 'production.example.test',
        publication: RoutePublication::Public,
        appInstanceId: null,
        nodeId: $this->node->id,
        clusterId: null,
    ))['route'];
    bind_route_reconciliation_provisioning();

    app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: $this->node->name,
        publicSshHost: $this->node->public_ssh_host,
        tldProvided: true,
        tld: null,
    ));

    expect($this->node->refresh()->tld)
        ->toBeNull()
        ->and($route->refresh()->only(['domain', 'node_id', 'cluster_id', 'status', 'failed_step', 'error_code']))
        ->toBe([
            'domain' => 'production.example.test',
            'node_id' => $this->node->id,
            'cluster_id' => null,
            'status' => RouteStatus::Pending,
            'failed_step' => null,
            'error_code' => null,
        ]);
});

it('reconciles Router DNS selection before an eligible Cluster attachment becomes authoritative', function (): void {
    $cluster = Cluster::query()->create(['name' => 'dns-attach', 'state' => ClusterState::Active, 'tld' => null]);
    $member = reconciliation_node('dns-attach-member', 'member.test');
    $dns = route_mutation_dns_reconciler();
    $dns->onExpand = static function () use ($member): void {
        expect($member->fresh()?->cluster_id)->toBeNull();
    };

    app(AttachClusterNodeAction::class)->execute($cluster, $member);

    expect($member->refresh()->cluster_id)
        ->toBe($cluster->id)
        ->and(array_column($dns->events, 'phase'))
        ->toBe(['expand', 'prune']);
});

it('keeps Cluster membership unchanged when DNS selection expansion fails during attach', function (): void {
    $cluster = Cluster::query()->create(['name' => 'dns-attach-fail', 'state' => ClusterState::Active, 'tld' => null]);
    $member = reconciliation_node('dns-attach-fail-member', 'member.test');
    $dns = route_mutation_dns_reconciler();
    $dns->expandFailure = new RuntimeConvergenceException(
        step: 'private-dns',
        errorCode: 'app-dev.dns_config_failed',
        message: 'DNS selection failed.',
    );

    expect(fn () => app(AttachClusterNodeAction::class)->execute($cluster, $member))
        ->toThrow(RuntimeConvergenceException::class);

    expect($member->refresh()->cluster_id)
        ->toBeNull()
        ->and(array_column($dns->events, 'phase'))
        ->toBe(['expand']);
});

it('inventories Node TLD changes and refuses an occupied generated domain before any write', function (): void {
    $this->target->update(['source_is_laravel' => false, 'provisioning_step' => 'active']);
    $this->node->update(['ssh_host_fingerprint' => 'SHA256:pinned']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $generated = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $generated->update(['status' => RouteStatus::Active]);
    $owner = reconciliation_node('occupied-owner', 'owner.test');
    reconciliation_route($this->orbitApp, 'feature.acme.next.test', node: $owner);
    $events = [];
    Route::updating(static function () use (&$events): void {
        $events[] = 'route';
    });
    Node::updating(static function () use (&$events): void {
        $events[] = 'node';
    });
    bind_node_tld_projection();

    expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: $this->node->name,
        publicSshHost: $this->node->public_ssh_host,
        tldProvided: true,
        tld: 'next.test',
    )))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('route.domain_conflict');
    });

    expect($generated->fresh()->domain)
        ->toBe('feature.acme.dev.test')
        ->and($this->node->fresh()->tld)
        ->toBe('dev.test')
        ->and($events)
        ->toBe([]);
});

it('prepares generated private projections before publishing a Node TLD change', function (): void {
    $this->target->update(['source_is_laravel' => true, 'provisioning_step' => 'active']);
    $this->node->update(['ssh_host_fingerprint' => 'SHA256:pinned']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $generated = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $generated->update(['status' => RouteStatus::Active]);
    $events = bind_node_tld_projection();

    $updated = app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: $this->node->name,
        publicSshHost: $this->node->public_ssh_host,
        tldProvided: true,
        tld: 'next.test',
    ));
    $replaced = reconciliation_route_by_domain('feature.acme.next.test');

    expect($events->values)
        ->toBe([
            'workload-certificate',
            'workload-caddy',
            'router-certificate',
            'firewall-policy',
            'workload-verify',
            'router-caddy',
            'url:https://feature.acme.next.test',
            'dns-publication',
            'cleanup',
            'url:https://feature.acme.next.test',
            'workload-verify',
            'tld',
        ])
        ->and($updated->tld)
        ->toBe('next.test')
        ->and($replaced->id)
        ->not->toBe($generated->id)
        ->and($replaced->status)
        ->toBe(RouteStatus::Active)
        ->and($replaced->provenance)
        ->toBe(RouteProvenance::Generated)
        ->and(Route::query()->find($generated->id))
        ->toBeNull();
});

it('keeps an explicit Route domain fixed when the Node TLD changes', function (): void {
    $this->target->update(['source_is_laravel' => false, 'provisioning_step' => 'active']);
    $this->node->update(['ssh_host_fingerprint' => 'SHA256:pinned']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $explicit = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $this->orbitApp->id,
        domain: 'fixed.example.test',
        publication: RoutePublication::Private,
        appInstanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $explicit->update(['status' => RouteStatus::Active]);
    bind_node_tld_projection();

    app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: $this->node->name,
        publicSshHost: $this->node->public_ssh_host,
        tldProvided: true,
        tld: 'next.test',
    ));

    expect($explicit->refresh()->domain)
        ->toBe('fixed.example.test')
        ->and($explicit->id)
        ->toBe($explicit->id)
        ->and($this->node->refresh()->tld)
        ->toBe('next.test');
});

it('clears a Node TLD onto the active Cluster TLD for a targeted generated Route', function (): void {
    $cluster = reconciliation_active_cluster('fallback-targeted', 'cluster.test');
    $this->target->update(['source_is_laravel' => false, 'provisioning_step' => 'active']);
    $this->node->update([
        'cluster_id' => $cluster->id,
        'ssh_host_fingerprint' => 'SHA256:pinned',
    ]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $generated = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $generated->update(['status' => RouteStatus::Active]);
    bind_node_tld_projection();

    app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: $this->node->name,
        publicSshHost: $this->node->public_ssh_host,
        tldProvided: true,
        tld: null,
    ));
    $replaced = reconciliation_route_by_domain('feature.acme.cluster.test');

    expect($this->node->refresh()->tld)
        ->toBeNull()
        ->and($replaced->id)
        ->not->toBe($generated->id)
        ->and($replaced->status)
        ->toBe(RouteStatus::Active)
        ->and($replaced->cluster_id)
        ->toBe($cluster->id);
});

it('does not return reconciliation_required after a Node TLD change and still refuses Cluster mutations', function (): void {
    $this->target->update(['source_is_laravel' => false, 'provisioning_step' => 'active']);
    $this->node->update(['ssh_host_fingerprint' => 'SHA256:pinned']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $generated = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $generated->update(['status' => RouteStatus::Active]);
    bind_node_tld_projection();

    app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: $this->node->name,
        publicSshHost: $this->node->public_ssh_host,
        tldProvided: true,
        tld: 'next.test',
    ));
    $first = reconciliation_route_by_domain('feature.acme.next.test');

    app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: $this->node->name,
        publicSshHost: $this->node->public_ssh_host,
        tldProvided: true,
        tld: 'later.test',
    ));
    $second = reconciliation_route_by_domain('feature.acme.later.test');
    $cluster = reconciliation_active_cluster('still-refused', 'cluster.test');
    $before = $second->fresh(['targets'])->toArray();

    expect($first->id)
        ->not->toBe($generated->id)
        ->and($second->id)
        ->not->toBe($first->id)
        ->and(fn () => app(AttachClusterNodeAction::class)->execute($cluster, $this->node->refresh()))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('route.reconciliation_required');
        })
        ->and($second->fresh(['targets'])->toArray())
        ->toBe($before);
});

it('requires a Router only after a TLD-less active Cluster owns a Route', function (): void {
    $cluster = Cluster::query()->create(['name' => 'tldless', 'state' => ClusterState::Active, 'tld' => null]);
    $member = reconciliation_node('member', 'member.test');
    $member->update(['cluster_id' => $cluster->id]);
    $memberTarget = reconciliation_instance($this->orbitApp, $member, 'member');
    $explicit = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $this->orbitApp->id,
        domain: 'fixed.example.test',
        publication: RoutePublication::Private,
        appInstanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $before = $explicit->fresh(['targets'])->toArray();

    expect($cluster->routerAssignment()->count())
        ->toBe(0)
        ->and(fn () => app(CreateRouteAction::class)->ensureForAppInstance($memberTarget, null))
        ->toThrow(ResourceOperationException::class, 'requires one active Router')
        ->and(fn () => app(SetRouteTargetAction::class)->execute($explicit, $memberTarget->id))
        ->toThrow(ResourceOperationException::class, 'requires one active Router');
    expect($explicit->fresh(['targets'])->toArray())->toBe($before);

    $router = reconciliation_node('tldless-router', null);
    $router->update(['cluster_id' => $cluster->id]);
    $router
        ->roles()
        ->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);

    expect(app(CreateRouteAction::class)->ensureForAppInstance($memberTarget, null)->cluster_id)
        ->toBe($cluster->id)
        ->and(fn () => app(SetRouteTargetAction::class)->execute($explicit, $memberTarget->id))
        ->toThrow(
            ResourceOperationException::class,
            "AppInstance [{$memberTarget->id}] is already associated with Route",
        );
});

function route_mutation_dns_reconciler(): FakeClusterRouterDnsSelectionReconciler
{
    $dns = app(ClusterRouterDnsSelectionReconciler::class);
    assert($dns instanceof FakeClusterRouterDnsSelectionReconciler);

    return $dns;
}

function reconciliation_node(string $name, ?string $tld): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => $tld,
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => '10.44.0.'.(Node::query()->count() + 20),
        'user' => 'orbit',
    ]);
}

function bind_node_tld_projection(): NodeTldProjectionEvents
{
    $events = new NodeTldProjectionEvents;
    app()->instance(RouteDomainProjector::class, new NodeTldRouteProjector($events));
    app()->instance(DevelopmentAppInstanceConfigurator::class, new NodeTldRouteConfigurator($events));
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteMutationProjectionOwner);
    app()->instance(AppDevTldConverger::class, new class($events) implements AppDevTldConverger
    {
        public function __construct(
            private NodeTldProjectionEvents $events,
        ) {}

        public function converge(Node $node): void
        {
            $this->events->values[] = 'tld';
        }
    });

    return $events;
}

function bind_route_reconciliation_provisioning(?Closure $onConverge = null): void
{
    app()->instance(NodeConverger::class, new class($onConverge) implements NodeConverger
    {
        public function __construct(
            private readonly ?Closure $onConverge,
        ) {}

        public function converge(
            Node $node,
            NodeProvisioningIdentity $identity,
            ?string $expectedSshHostFingerprint = null,
            bool $rolelessOperator = false,
        ): NodeObservation {
            if ($this->onConverge instanceof Closure) {
                ($this->onConverge)($node);
            }

            return new NodeObservation('x86_64');
        }
    });
    app()->instance(AppDevTldConverger::class, new class implements AppDevTldConverger
    {
        public function converge(Node $node): void {}
    });
    app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('reconcile')->zeroOrMoreTimes()->withNoArgs();
    app()->instance(MetricsFleetReconciler::class, $metrics);
}

function reconciliation_instance(OrbitApp $app, Node $node, string $name): AppInstance
{
    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/srv/{$name}",
        'branch' => $name,
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::Active,
    ]);
}

function reconciliation_active_cluster(string $name, ?string $tld): Cluster
{
    $cluster = Cluster::query()->create(['name' => $name, 'tld' => $tld, 'state' => ClusterState::Active]);
    $router = reconciliation_node("{$name}-router", null);
    $router->update(['cluster_id' => $cluster->id]);
    $router
        ->roles()
        ->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);

    return $cluster;
}

function reconciliation_route_by_domain(string $domain): Route
{
    return Route::query()->where('domain', $domain)->sole();
}

function reconciliation_route(
    OrbitApp $app,
    string $domain,
    ?Node $node = null,
    ?Cluster $cluster = null,
    ?Node $basis = null,
): Route {
    return Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node?->id,
        'cluster_id' => $cluster?->id,
        'generation_basis_node_id' => $basis?->id,
        'domain' => $domain,
        'provenance' => $basis === null ? RouteProvenance::Explicit : RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
}

function reconciliation_update(
    bool $tldProvided = false,
    ?string $tld = null,
    ?ClusterState $state = null,
): UpdateClusterData {
    return new UpdateClusterData(
        nameProvided: false,
        name: null,
        tldProvided: $tldProvided,
        tld: $tld,
        stateProvided: $state !== null,
        state: $state,
    );
}

final readonly class RouteMutationProjectionOwner implements DevelopmentProjectionOperationLock
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}

final class RouteReconciliationClusterRouterOperationLock implements ClusterRouterOperationLock
{
    /** @var list<int> */
    public array $clusterIds = [];

    public bool $active = false;

    public function run(int $clusterId, Closure $operation): mixed
    {
        $this->clusterIds[] = $clusterId;
        $this->active = true;

        try {
            return $operation();
        } finally {
            $this->active = false;
        }
    }
}

final class NodeTldProjectionEvents
{
    /** @var list<string> */
    public array $values = [];
}

final class NodeTldRouteProjector implements RouteDomainProjector
{
    public function __construct(
        private NodeTldProjectionEvents $events,
    ) {}

    public function prepareWorkloadCertificate(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $this->events->values[] = 'workload-certificate';
    }

    public function prepareWorkloadCaddy(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $this->events->values[] = 'workload-caddy';
    }

    public function prepareRouterCertificate(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $this->events->values[] = 'router-certificate';
    }

    public function prepareFirewallPolicy(AppInstance $appInstance, Route $candidate): void
    {
        $this->events->values[] = 'firewall-policy';
    }

    public function verifyWorkload(AppInstance $appInstance, Route $candidate): void
    {
        $this->events->values[] = 'workload-verify';
    }

    public function prepareRouterCaddy(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $this->events->values[] = 'router-caddy';
    }

    public function prepareIngressCertificate(Route $candidate): void {}

    public function stageIngressCaddy(Route $candidate): void {}

    public function prepareIngressFirewall(Route $candidate): void {}

    public function verifyPublicEdge(Route $candidate): void {}

    public function activatePublicHandler(Route $candidate): void {}

    public function rollbackPublicEdge(Route $route): void {}

    public function publishDns(Route $current, Route $candidate): void
    {
        $this->events->values[] = 'dns-publication';
    }

    public function cleanup(AppInstance $appInstance, Route $route): void
    {
        $this->events->values[] = 'cleanup';
    }

    public function rollbackDns(Route $route): void
    {
        $this->events->values[] = 'rollback-dns';
    }

    public function rollbackCaddy(AppInstance $appInstance, Route $route): void
    {
        $this->events->values[] = 'rollback-caddy';
    }

    public function rollbackCertificates(AppInstance $appInstance, Route $route): void
    {
        $this->events->values[] = 'rollback-certificates';
    }
}

final class NodeTldRouteConfigurator implements DevelopmentAppInstanceConfigurator
{
    public function __construct(
        private NodeTldProjectionEvents $events,
    ) {}

    public function inspect(AppInstance $appInstance): DevelopmentSourceProfile
    {
        return new DevelopmentSourceProfile('8.5', (bool) $appInstance->source_is_laravel);
    }

    public function configureLaravelUrl(AppInstance $appInstance, string $url): void
    {
        $this->events->values[] = "url:{$url}";
    }
}
