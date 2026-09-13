<?php

declare(strict_types=1);

use App\Actions\Clusters\AttachClusterNodeAction;
use App\Actions\Clusters\DetachClusterNodeAction;
use App\Actions\Clusters\UpdateClusterAction;
use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Clusters\UpdateClusterData;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Clusters\ClusterState;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Tests\Support\FakeRouterLanIngressReconciler;
use Tests\Support\FakeToolManagerMaterializer;

it('expands Router LAN access before Cluster attachment becomes authoritative', function (): void {
    [$cluster, $member, $reconciler] = router_lan_reconciliation_cluster();

    expect($member->cluster_id)->toBeNull();

    $reconciler->onExpand = static function () use ($member): void {
        expect($member->fresh()?->cluster_id)->toBeNull();
    };

    app(AttachClusterNodeAction::class)->execute($cluster, $member);

    expect($member->refresh()->cluster_id)
        ->toBe($cluster->id)
        ->and(array_column($reconciler->events, 'phase'))
        ->toBe(['expand', 'prune'])
        ->and($reconciler->events[0]['nodeOverrides'][$member->id]['cluster_id'])
        ->toBe($cluster->id);
});

it('keeps Cluster membership unchanged when LAN expansion fails', function (): void {
    [$cluster, $member, $reconciler] = router_lan_reconciliation_cluster();
    $reconciler->expandFailure = new FirewallOperationException(
        step: 'host-firewall',
        errorCode: 'node.firewall_convergence_failed',
        message: 'LAN expansion failed.',
    );

    expect(fn () => app(AttachClusterNodeAction::class)->execute($cluster, $member))
        ->toThrow(FirewallOperationException::class);

    expect($member->refresh()->cluster_id)
        ->toBeNull()
        ->and(array_column($reconciler->events, 'phase'))
        ->toBe(['expand']);
});

it('prunes obsolete LAN access only after a successful detach', function (): void {
    [$cluster, $member, $reconciler] = router_lan_reconciliation_cluster();
    $member->update(['cluster_id' => $cluster->id]);

    app(DetachClusterNodeAction::class)->execute($cluster, $member);

    expect($member->refresh()->cluster_id)
        ->toBeNull()
        ->and(array_column($reconciler->events, 'phase'))
        ->toBe(['expand', 'prune'])
        ->and($reconciler->events[1]['nodeOverrides'][$member->id]['cluster_id'])
        ->toBeNull();
});

it('keeps the detached membership when obsolete LAN prune fails after the transition', function (): void {
    [$cluster, $member, $reconciler] = router_lan_reconciliation_cluster();
    $member->update(['cluster_id' => $cluster->id]);
    $reconciler->pruneFailure = new FirewallOperationException(
        step: 'host-firewall',
        errorCode: 'node.firewall_convergence_failed',
        message: 'LAN prune failed.',
    );

    expect(fn () => app(DetachClusterNodeAction::class)->execute($cluster, $member))
        ->toThrow(FirewallOperationException::class);

    expect($member->refresh()->cluster_id)->toBeNull();
});

it('expands LAN policy before Cluster activation and prunes after deactivation', function (): void {
    [$cluster, , $reconciler] = router_lan_reconciliation_cluster();

    app(UpdateClusterAction::class)->execute($cluster, new UpdateClusterData(
        nameProvided: false,
        name: null,
        tldProvided: false,
        tld: null,
        stateProvided: true,
        state: ClusterState::Active,
    ));

    expect($cluster->refresh()->state)
        ->toBe(ClusterState::Active)
        ->and($reconciler->events[0])
        ->toMatchArray([
            'phase' => 'expand',
            'clusterOverrides' => [$cluster->id => ['state' => ClusterState::Active]],
        ]);

    $reconciler->events = [];

    app(UpdateClusterAction::class)->execute($cluster, new UpdateClusterData(
        nameProvided: false,
        name: null,
        tldProvided: false,
        tld: null,
        stateProvided: true,
        state: ClusterState::Inactive,
    ));

    expect($cluster->refresh()->state)
        ->toBe(ClusterState::Inactive)
        ->and(array_column($reconciler->events, 'phase'))
        ->toBe(['expand', 'prune']);
});

it('leaves Cluster state unchanged when activation expansion fails', function (): void {
    [$cluster, , $reconciler] = router_lan_reconciliation_cluster();
    $reconciler->expandFailure = new FirewallOperationException(
        step: 'host-firewall',
        errorCode: 'node.firewall_convergence_failed',
        message: 'LAN expansion failed.',
    );

    expect(fn () => app(UpdateClusterAction::class)->execute($cluster, new UpdateClusterData(
        nameProvided: false,
        name: null,
        tldProvided: false,
        tld: null,
        stateProvided: true,
        state: ClusterState::Active,
    )))->toThrow(FirewallOperationException::class);

    expect($cluster->refresh()->state)->toBe(ClusterState::Inactive);
});

it('expands Router LAN access before provision marks the Node active', function (): void {
    [$cluster, , $reconciler] = router_lan_reconciliation_cluster();
    router_lan_provision_fakes();
    $reconciler->onExpand = static function () use ($cluster): void {
        expect(Node::query()->where('name', 'lan-provisioned-member')->sole()->status)
            ->toBe(LifecycleStatus::Provisioning)
            ->and($cluster->refresh()->state)
            ->toBe(ClusterState::Inactive);
    };

    $node = app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: 'lan-provisioned-member',
        publicSshHost: '192.0.2.80',
        architecture: 'x86_64',
        expectedSshHostFingerprint: 'SHA256:pinned',
        clusterProvided: true,
        clusterId: $cluster->id,
        lanIpProvided: true,
        lanIp: '10.20.0.80',
        wireguardIp: '10.44.0.80',
    ));

    expect($node->status)
        ->toBe(LifecycleStatus::Active)
        ->and(array_column($reconciler->events, 'phase'))
        ->toBe(['expand', 'prune'])
        ->and($reconciler->events[0]['nodeOverrides'][$node->id]['status'])
        ->toBe(LifecycleStatus::Active)
        ->and($reconciler->events[0]['nodeOverrides'][$node->id]['lan_ip'])
        ->toBe('10.20.0.80');
});

it('keeps provision from becoming active when LAN expansion fails', function (): void {
    [$cluster, , $reconciler] = router_lan_reconciliation_cluster();
    router_lan_provision_fakes();
    $reconciler->expandFailure = new FirewallOperationException(
        step: 'host-firewall',
        errorCode: 'node.firewall_convergence_failed',
        message: 'LAN expansion failed.',
    );

    expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData(
        name: 'lan-provision-failure',
        publicSshHost: '192.0.2.81',
        architecture: 'x86_64',
        expectedSshHostFingerprint: 'SHA256:pinned',
        clusterProvided: true,
        clusterId: $cluster->id,
        lanIpProvided: true,
        lanIp: '10.20.0.81',
        wireguardIp: '10.44.0.81',
    )))->toThrow(NodeProvisioningException::class);

    $node = Node::query()->where('name', 'lan-provision-failure')->sole();

    expect($node->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($node->failed_step)
        ->toBe('router-lan-ingress');
});

/**
 * @return array{Cluster, Node, FakeRouterLanIngressReconciler}
 */
function router_lan_reconciliation_cluster(): array
{
    $cluster = Cluster::query()->create(['name' => 'lan-reconciliation']);
    $router = Node::query()->create([
        'name' => 'reconciliation-router',
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.40',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.40',
        'lan_ip' => '10.20.0.40',
        'wireguard_public_key' => 'router-key',
    ]);
    $router->roles()->create([
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
        'cluster_id' => $cluster->id,
    ]);
    $member = Node::query()->create([
        'name' => 'reconciliation-member',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.41',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.41',
        'lan_ip' => '10.20.0.41',
        'wireguard_public_key' => 'member-key',
    ]);
    $reconciler = new FakeRouterLanIngressReconciler;
    app()->instance(RouterLanIngressReconciler::class, $reconciler);

    return [$cluster, $member, $reconciler];
}

function router_lan_provision_fakes(): void
{
    app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
    app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger
    {
        public function converge(Node $node, NodeRole $assignment): void {}

        public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

        public function removeUnreachable(Node $node, NodeRole $assignment): void {}
    });
    app()->instance(NodeConverger::class, new class implements NodeConverger
    {
        public function converge(
            Node $node,
            NodeProvisioningIdentity $identity,
            ?string $expectedSshHostFingerprint = null,
            bool $rolelessOperator = false,
        ): NodeObservation {
            return new NodeObservation('x86_64');
        }
    });
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('reconcile')->zeroOrMoreTimes();
    app()->instance(MetricsFleetReconciler::class, $metrics);
}
