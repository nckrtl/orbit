<?php

declare(strict_types=1);

use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Support\Str;
use Tests\Support\FakeClusterRouterDnsSelectionReconciler;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(cluster_nodes_api_node('gateway', '10.44.0.1'));
    $this->firstCluster = Cluster::query()->create(['name' => 'first']);
    $this->secondCluster = Cluster::query()->create(['name' => 'second']);
    $this->node = cluster_nodes_api_node('app-dev', '10.44.0.2');
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

it('attaches one active Node to one Cluster and exposes membership in both resources', function (): void {
    $requestId = (string) Str::uuid();
    $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->putJson("/api/v1/clusters/{$this->firstCluster->id}/nodes/{$this->node->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->firstCluster->id)
        ->assertJsonPath('data.nodes.0.id', $this->node->id);
    expect(Activity::query()->where('request_id', $requestId)->sole()->command)
        ->toBe('cluster:node:add');

    $this
        ->getJson("/api/v1/nodes/{$this->node->id}")
        ->assertOk()
        ->assertJsonPath('data.cluster_id', $this->firstCluster->id);

    expect($this->node->refresh()->cluster_id)->toBe($this->firstCluster->id);
});

it('reconciles Router DNS selection before Cluster attachment becomes authoritative', function (): void {
    $dns = cluster_nodes_dns_reconciler();
    $dns->onExpand = function (): void {
        expect($this->node->fresh()?->cluster_id)->toBeNull();
    };

    $this
        ->putJson("/api/v1/clusters/{$this->firstCluster->id}/nodes/{$this->node->id}")
        ->assertOk();

    expect($this->node->refresh()->cluster_id)
        ->toBe($this->firstCluster->id)
        ->and(array_column($dns->events, 'phase'))
        ->toBe(['expand', 'prune'])
        ->and($dns->events[0]['nodeOverrides'][$this->node->id]['cluster_id'])
        ->toBe($this->firstCluster->id);
});

it('keeps Cluster membership unchanged when DNS selection expansion fails', function (): void {
    $dns = cluster_nodes_dns_reconciler();
    $dns->expandFailure = new RuntimeConvergenceException(
        step: 'private-dns',
        errorCode: 'app-dev.dns_config_failed',
        message: 'DNS selection failed.',
    );

    $this
        ->putJson("/api/v1/clusters/{$this->firstCluster->id}/nodes/{$this->node->id}")
        ->assertServerError();

    expect($this->node->refresh()->cluster_id)
        ->toBeNull()
        ->and(array_column($dns->events, 'phase'))
        ->toBe(['expand']);
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

    $requestId = (string) Str::uuid();
    $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->deleteJson(
            "/api/v1/clusters/{$this->firstCluster->id}/nodes/{$this->node->id}",
            ['force' => true],
        )
        ->assertOk()
        ->assertJsonPath('data.nodes', []);

    expect($this->node->refresh()->cluster_id)
        ->toBeNull()
        ->and(Activity::query()->where('request_id', $requestId)->sole()->command)
        ->toBe('cluster:node:remove');
});

it('reconciles Router DNS selection before detach becomes authoritative', function (): void {
    $this->node->update(['cluster_id' => $this->firstCluster->id]);
    $dns = cluster_nodes_dns_reconciler();
    $dns->onExpand = function (): void {
        expect($this->node->fresh()?->cluster_id)->toBe($this->firstCluster->id);
    };

    $this
        ->deleteJson(
            "/api/v1/clusters/{$this->firstCluster->id}/nodes/{$this->node->id}",
            ['force' => true],
        )
        ->assertOk();

    expect($this->node->refresh()->cluster_id)
        ->toBeNull()
        ->and(array_column($dns->events, 'phase'))
        ->toBe(['expand', 'prune'])
        ->and($dns->events[0]['nodeOverrides'][$this->node->id]['cluster_id'])
        ->toBeNull();
});

it('refuses to detach a matching-TLD Node from an active TLD-bearing Cluster', function (): void {
    $this->firstCluster->update([
        'tld' => 'beast',
        'state' => 'active',
    ]);
    $this->node->update([
        'cluster_id' => $this->firstCluster->id,
        'tld' => 'beast',
    ]);
    $dns = cluster_nodes_dns_reconciler();

    $this
        ->deleteJson(
            "/api/v1/clusters/{$this->firstCluster->id}/nodes/{$this->node->id}",
            ['force' => true],
        )
        ->assertConflict()
        ->assertJsonPath('error.code', 'cluster.tld_conflict');

    expect($this->node->refresh()->cluster_id)
        ->toBe($this->firstCluster->id)
        ->and(array_column($dns->events, 'phase'))
        ->toBe(['expand', 'prune']);

    $this
        ->patchJson("/api/v1/clusters/{$this->firstCluster->id}", ['tld' => null])
        ->assertOk();
    $this
        ->deleteJson(
            "/api/v1/clusters/{$this->firstCluster->id}/nodes/{$this->node->id}",
            ['force' => true],
        )
        ->assertOk();

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

function cluster_nodes_dns_reconciler(): FakeClusterRouterDnsSelectionReconciler
{
    $dns = app(ClusterRouterDnsSelectionReconciler::class);
    assert($dns instanceof FakeClusterRouterDnsSelectionReconciler);

    return $dns;
}
