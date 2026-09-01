<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Cluster;
use App\Models\Node;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(cluster_nodes_api_node('gateway', '10.44.0.1'));
    $this->firstCluster = Cluster::query()->create(['name' => 'first']);
    $this->secondCluster = Cluster::query()->create(['name' => 'second']);
    $this->node = cluster_nodes_api_node('app-dev', '10.44.0.2');
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

it('attaches one active Node to one Cluster and exposes membership in both resources', function (): void {
    $this
        ->putJson("/api/v1/clusters/{$this->firstCluster->id}/nodes/{$this->node->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->firstCluster->id)
        ->assertJsonPath('data.nodes.0.id', $this->node->id);

    $this
        ->getJson("/api/v1/nodes/{$this->node->id}")
        ->assertOk()
        ->assertJsonPath('data.cluster_id', $this->firstCluster->id);

    expect($this->node->refresh()->cluster_id)->toBe($this->firstCluster->id);
});

it('rejects a second simultaneous Cluster membership without changing the first', function (): void {
    $this->node->update(['cluster_id' => $this->firstCluster->id]);

    $this
        ->putJson("/api/v1/clusters/{$this->secondCluster->id}/nodes/{$this->node->id}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'cluster.membership_conflict');

    expect($this->node->refresh()->cluster_id)->toBe($this->firstCluster->id);
});

it('requires explicit consent to detach a Node and preserves membership on refusal', function (): void {
    $this->node->update(['cluster_id' => $this->firstCluster->id]);

    $this
        ->deleteJson("/api/v1/clusters/{$this->firstCluster->id}/nodes/{$this->node->id}")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect($this->node->refresh()->cluster_id)->toBe($this->firstCluster->id);

    $this
        ->deleteJson(
            "/api/v1/clusters/{$this->firstCluster->id}/nodes/{$this->node->id}",
            ['force' => true],
        )
        ->assertOk()
        ->assertJsonPath('data.nodes', []);

    expect($this->node->refresh()->cluster_id)->toBeNull();
});

it('protects Cluster membership until every persisted Ingress lifecycle row is deleted', function (
    LifecycleStatus $status,
    ?string $failedStep,
): void {
    $this->node->update(['cluster_id' => $this->firstCluster->id]);
    $assignment = $this->node
        ->roles()
        ->create([
            'role' => RoleName::Ingress,
            'status' => $status,
            'cluster_id' => $this->firstCluster->id,
            'failed_step' => $failedStep,
        ]);

    $this
        ->deleteJson(
            "/api/v1/clusters/{$this->firstCluster->id}/nodes/{$this->node->id}",
            ['force' => true],
        )
        ->assertConflict()
        ->assertJsonPath('error.code', 'cluster.ingress_detach_forbidden');

    expect($this->node->refresh()->cluster_id)
        ->toBe($this->firstCluster->id)
        ->and($assignment->fresh()?->status)
        ->toBe($status);

    $assignment->delete();

    $this
        ->deleteJson(
            "/api/v1/clusters/{$this->firstCluster->id}/nodes/{$this->node->id}",
            ['force' => true],
        )
        ->assertOk();

    expect($this->node->refresh()->cluster_id)->toBeNull();
})->with([
    'provisioning' => [LifecycleStatus::Provisioning, null],
    'active' => [LifecycleStatus::Active, null],
    'removing' => [LifecycleStatus::Removing, null],
    'retryable convergence failure' => [LifecycleStatus::Failed, 'converge:baseline'],
    'retryable removal failure' => [LifecycleStatus::Failed, 'remove:baseline'],
]);

it('rejects removal of a non-empty Cluster without changing membership', function (): void {
    $this->node->update(['cluster_id' => $this->firstCluster->id]);

    $this
        ->deleteJson("/api/v1/clusters/{$this->firstCluster->id}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'cluster.not_empty');

    expect($this->firstCluster->fresh())
        ->not
        ->toBeNull()
        ->and($this->node->refresh()->cluster_id)
        ->toBe($this->firstCluster->id);
});

function cluster_nodes_api_node(string $name, string $wireguardIp): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.'.str_replace('10.44.0.', '', $wireguardIp),
        'wireguard_ip' => $wireguardIp,
    ]);
}
