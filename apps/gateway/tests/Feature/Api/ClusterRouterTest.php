<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(cluster_router_api_node('gateway-router-peer', '10.44.0.1'));
    $this->cluster = Cluster::query()->create(['name' => 'development']);
    $this->first = cluster_router_api_node('first-router', '10.44.0.2', $this->cluster);
    $this->second = cluster_router_api_node('second-router', '10.44.0.3', $this->cluster);
    $this->first
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

it('sets a Router beside an application role and rejects generic Router mutation', function (): void {
    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")
        ->assertOk()
        ->assertJsonPath('data.router.id', $this->first->id)
        ->assertJsonPath('data.router.name', 'first-router');

    expect(
        $this
            ->first->refresh()
            ->roles->pluck('role')
            ->map->value->all(),
    )
        ->toBe(['app-dev', 'router']);

    $this
        ->postJson("/api/v1/nodes/{$this->second->id}/roles", ['role' => 'router'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    $this
        ->deleteJson("/api/v1/nodes/{$this->first->id}/roles/router", ['force' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect($this->cluster->routerAssignment()->sole()->node_id)->toBe($this->first->id);
});

it('atomically replaces the Router and preserves exactly one assignment', function (): void {
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertOk();

    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")
        ->assertOk()
        ->assertJsonPath('data.router.id', $this->second->id);

    expect(NodeRole::query()->where('role', RoleName::Router)->count())
        ->toBe(1)
        ->and($this->cluster->routerAssignment()->sole()->node_id)
        ->toBe($this->second->id);
});

it('requires an active member Node for Router assignment', function (): void {
    $outside = cluster_router_api_node('outside', '10.44.0.4');

    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$outside->id}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'cluster.router_node_invalid');

    $this->second->update(['status' => LifecycleStatus::Failed]);

    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'cluster.router_node_invalid');

    expect($this->cluster->routerAssignment()->exists())->toBeFalse();
});

it('refuses to clear or detach an active Router until the Cluster is inactive', function (): void {
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertOk();
    $this->patchJson("/api/v1/clusters/{$this->cluster->id}", ['state' => 'active'])->assertOk();

    $this
        ->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true])
        ->assertConflict()
        ->assertJsonPath('error.code', 'cluster.active_router_required');

    $this
        ->deleteJson(
            "/api/v1/clusters/{$this->cluster->id}/nodes/{$this->first->id}",
            ['force' => true],
        )
        ->assertConflict()
        ->assertJsonPath('error.code', 'cluster.router_detach_forbidden');

    $this->patchJson("/api/v1/clusters/{$this->cluster->id}", ['state' => 'inactive'])->assertOk();

    $this
        ->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true])
        ->assertOk()
        ->assertJsonPath('data.router', null);

    expect($this->cluster->routerAssignment()->exists())->toBeFalse();
});

function cluster_router_api_node(string $name, string $wireguardIp, ?Cluster $cluster = null): Node
{
    return Node::query()->create([
        'cluster_id' => $cluster?->id,
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.'.str_replace('10.44.0.', '', $wireguardIp),
        'wireguard_ip' => $wireguardIp,
    ]);
}
