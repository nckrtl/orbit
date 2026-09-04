<?php

declare(strict_types=1);

use App\Actions\Clusters\AttachClusterNodeAction;
use App\Actions\Clusters\DetachClusterNodeAction;
use App\Actions\Clusters\UpdateClusterAction;
use App\Actions\Nodes\ProvisionNodeAction;
use App\Actions\Routes\ClearRouteTargetAction;
use App\Actions\Routes\CreateRouteAction;
use App\Actions\Routes\SetRouteTargetAction;
use App\Data\Clusters\UpdateClusterData;
use App\Data\Nodes\ProvisionNodeData;
use App\Data\Routes\CreateRouteData;
use App\Domain\AppDev\AppDevTldConverger;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RoutePublication;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Tests\Support\FakeToolManagerMaterializer;

beforeEach(function (): void {
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'main_branch' => 'main',
        'root' => 'public',
    ]);
    $this->node = reconciliation_node('dev', 'dev.test');
    $this->target = reconciliation_instance($this->orbitApp, $this->node, 'feature');
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
    expect($route->refresh()->cluster_id)
        ->toBe($cluster->id)
        ->and($route->hostname)
        ->toBe('feature.acme.dev.test')
        ->and($route->status->value)
        ->toBe('pending');

    $this->node->update(['tld' => null]);
    app(UpdateClusterAction::class)->execute($cluster, reconciliation_update(tldProvided: true, tld: 'next.test'));
    expect($route->refresh()->hostname)->toBe('feature.acme.next.test');

    $this->node->update(['tld' => 'node.test']);
    app(UpdateClusterAction::class)->execute($cluster, reconciliation_update(state: ClusterState::Inactive));
    expect($route->refresh()->node_id)->toBe($this->node->id)->and($route->hostname)->toBe('feature.acme.node.test');

    app(DetachClusterNodeAction::class)->execute($cluster, $this->node);
    expect($this->node->refresh()->cluster_id)->toBeNull()->and($route->refresh()->node_id)->toBe($this->node->id);
});

it('reconciles a zero-target generated Route from its retained basis', function (): void {
    $cluster = reconciliation_active_cluster('routing', 'old.test');
    $this->node->update(['cluster_id' => $cluster->id, 'tld' => null]);
    $route = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    app(ClearRouteTargetAction::class)->execute($route);

    app(UpdateClusterAction::class)->execute($cluster, reconciliation_update(tldProvided: true, tld: 'new.test'));

    expect($route->refresh()->hostname)
        ->toBe('feature.acme.new.test')
        ->and($route->generation_basis_node_id)
        ->toBe($this->node->id)
        ->and($route->targets()->count())
        ->toBe(0)
        ->and($route->status->value)
        ->toBe('pending');
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
    app(ClearRouteTargetAction::class)->execute($route);
    $this->target->delete();
    $observed = [];
    bind_route_reconciliation_provisioning(function (Node $node) use (&$observed, $route): void {
        $observed = [
            'node_tld' => $node->fresh()->tld,
            'node_status' => $node->fresh()->status,
            'route_hostname' => $route->fresh()->hostname,
            'route_status' => $route->fresh()->status,
        ];
    });

    app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: $this->node->name,
        publicSshHost: $this->node->public_ssh_host,
        tldProvided: true,
        tld: 'new.test',
    ));

    expect($observed)
        ->toBe([
            'node_tld' => 'new.test',
            'node_status' => LifecycleStatus::Provisioning,
            'route_hostname' => 'feature.acme.new.test',
            'route_status' => \App\Domain\Routes\RouteStatus::Pending,
        ])
        ->and($route->refresh()->only(['hostname', 'generation_basis_node_id', 'failed_step', 'error_code']))
        ->toBe([
            'hostname' => 'feature.acme.new.test',
            'generation_basis_node_id' => $this->node->id,
            'failed_step' => null,
            'error_code' => null,
        ]);
});

it('preserves Node and Route state when the last app-dev TLD has no active fallback', function (): void {
    $this->node->update(['ssh_host_fingerprint' => 'SHA256:pinned']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $route = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
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
    app(ClearRouteTargetAction::class)->execute($route);
    $this->target->delete();
    bind_route_reconciliation_provisioning();

    app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: $this->node->name,
        publicSshHost: $this->node->public_ssh_host,
        tldProvided: true,
        tld: null,
    ));

    expect($this->node->refresh()->tld)
        ->toBeNull()
        ->and($route->refresh()->hostname)
        ->toBe('feature.acme.cluster.test')
        ->and($route->cluster_id)
        ->toBe($cluster->id)
        ->and($route->status->value)
        ->toBe('pending');
});

it('keeps an explicit app-prod Route valid when its Node has no TLD', function (): void {
    $this->node->update(['ssh_host_fingerprint' => 'SHA256:pinned']);
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $this->target->delete();
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $this->orbitApp->id,
        hostname: 'production.example.test',
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
        ->and($route->refresh()->only(['hostname', 'node_id', 'cluster_id', 'status', 'failed_step', 'error_code']))
        ->toBe([
            'hostname' => 'production.example.test',
            'node_id' => $this->node->id,
            'cluster_id' => null,
            'status' => \App\Domain\Routes\RouteStatus::Pending,
            'failed_step' => null,
            'error_code' => null,
        ]);
});

it('requires a Router only after a TLD-less active Cluster owns a Route', function (): void {
    $cluster = Cluster::query()->create(['name' => 'tldless', 'state' => ClusterState::Active, 'tld' => null]);
    $member = reconciliation_node('member', 'member.test');
    $member->update(['cluster_id' => $cluster->id]);
    $memberTarget = reconciliation_instance($this->orbitApp, $member, 'member');
    $explicit = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $this->orbitApp->id,
        hostname: 'fixed.example.test',
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
        ->and(app(SetRouteTargetAction::class)->execute($explicit, $memberTarget->id)->cluster_id)
        ->toBe($cluster->id);
});

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

function bind_route_reconciliation_provisioning(?Closure $onConverge = null): void
{
    app()->instance(NodeConverger::class, new class($onConverge) implements NodeConverger {
        public function __construct(
            private readonly ?Closure $onConverge,
        ) {}

        public function converge(
            Node $node,
            NodeProvisioningIdentity $identity,
            ?string $expectedSshHostFingerprint = null,
            bool $rolelessOperator = false,
        ): void {
            if ($this->onConverge instanceof Closure) {
                ($this->onConverge)($node);
            }
        }
    });
    app()->instance(AppDevTldConverger::class, new class implements AppDevTldConverger {
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
