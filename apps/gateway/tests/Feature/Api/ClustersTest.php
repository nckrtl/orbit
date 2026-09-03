<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Cluster;
use App\Models\Node;

beforeEach(function (): void {
    $this->operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->operator = $this->markAsGateway($this->operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
});

describe('Cluster lifecycle', function (): void {
    it('creates, lists, shows, updates, and removes normalized Clusters', function (): void {
        $this->operator->update(['tld' => 'beast']);

        $withoutTld = $this
            ->postJson('/api/v1/clusters', ['name' => 'no-tld'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'no-tld')
            ->assertJsonPath('data.tld', null)
            ->assertJsonPath('data.state', 'inactive');

        $withTld = $this
            ->postJson('/api/v1/clusters', ['name' => 'development', 'tld' => '  Beast  '])
            ->assertCreated()
            ->assertJsonPath('data.tld', 'beast');

        expect($this->operator->refresh()->tld)
            ->toBe('beast')
            ->and($this->operator->cluster_id)
            ->toBeNull();

        $clusterId = $withTld->json('data.id');

        $this
            ->getJson('/api/v1/clusters')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$clusterId, $withoutTld->json('data.id')]);

        $this
            ->getJson("/api/v1/clusters/{$clusterId}")
            ->assertOk()
            ->assertJsonPath('data.name', 'development')
            ->assertJsonPath('data.router', null)
            ->assertJsonPath('data.nodes', []);

        $this
            ->patchJson("/api/v1/clusters/{$clusterId}", [
                'name' => 'local',
                'tld' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'local')
            ->assertJsonPath('data.tld', null);

        $this
            ->deleteJson("/api/v1/clusters/{$clusterId}")
            ->assertOk()
            ->assertJsonPath('data.id', $clusterId);

        expect(Cluster::query()->find($clusterId))->toBeNull();
    });

    it('rejects duplicate names, duplicate non-null TLDs, and malformed TLDs without mutation', function (): void {
        $cluster = Cluster::query()->create([
            'name' => 'development',
            'tld' => 'beast',
            'state' => 'inactive',
        ]);
        Cluster::query()->create([
            'name' => 'production',
            'state' => 'inactive',
        ]);

        $this
            ->postJson('/api/v1/clusters', ['name' => 'development'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        $this
            ->patchJson("/api/v1/clusters/{$cluster->id}", ['name' => 'production'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        $this
            ->postJson('/api/v1/clusters', ['name' => 'duplicate-tld', 'tld' => 'BEAST'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        $this
            ->postJson('/api/v1/clusters', ['name' => 'invalid-tld', 'tld' => 'dev.orbit'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect($cluster->refresh()->toArray())
            ->toMatchArray(['name' => 'development', 'tld' => 'beast', 'state' => 'inactive'])
            ->and(Cluster::query()->count())
            ->toBe(2);
    });

    it('activates a TLD-less Cluster without a Router', function (): void {
        $cluster = Cluster::query()->create([
            'name' => 'development',
            'state' => 'inactive',
        ]);

        $this
            ->patchJson("/api/v1/clusters/{$cluster->id}", ['state' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.tld', null)
            ->assertJsonPath('data.router', null);

        expect($cluster->refresh()->state)->toBe(ClusterState::Active);
    });

    it('requires a Router only for a proposed active TLD-bearing Cluster', function (): void {
        $withTld = Cluster::query()->create([
            'name' => 'with-tld',
            'tld' => 'beast',
            'state' => ClusterState::Inactive,
        ]);
        $withoutTld = Cluster::query()->create([
            'name' => 'without-tld',
            'state' => ClusterState::Active,
        ]);

        $this
            ->patchJson("/api/v1/clusters/{$withTld->id}", ['state' => 'active'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'cluster.router_required');
        $this
            ->patchJson("/api/v1/clusters/{$withoutTld->id}", ['tld' => 'orbit'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'cluster.router_required');

        expect($withTld->refresh()->state)
            ->toBe(ClusterState::Inactive)
            ->and($withoutTld->refresh()->tld)
            ->toBeNull();
    });

    it('validates combined updates against their proposed final state', function (): void {
        $cluster = Cluster::query()->create([
            'name' => 'development',
            'tld' => 'beast',
            'state' => ClusterState::Inactive,
        ]);

        $this
            ->patchJson("/api/v1/clusters/{$cluster->id}", [
                'tld' => null,
                'state' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.tld', null)
            ->assertJsonPath('data.state', 'active');

        $this
            ->patchJson("/api/v1/clusters/{$cluster->id}", [
                'tld' => 'beast',
                'state' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('data.tld', 'beast')
            ->assertJsonPath('data.state', 'inactive');
    });

    it('allows only member Nodes to share an active Cluster TLD', function (): void {
        $cluster = Cluster::query()->create([
            'name' => 'development',
            'tld' => 'beast',
            'state' => ClusterState::Inactive,
        ]);
        $router = Node::query()->create([
            'cluster_id' => $cluster->id,
            'name' => 'router',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.3',
            'wireguard_ip' => '10.44.0.3',
        ]);
        $router
            ->roles()
            ->create([
                'cluster_id' => $cluster->id,
                'role' => RoleName::Router,
                'status' => LifecycleStatus::Active,
            ]);
        $matching = Node::query()->create([
            'name' => 'matching',
            'tld' => 'beast',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.4',
            'wireguard_ip' => '10.44.0.4',
        ]);

        $this
            ->patchJson("/api/v1/clusters/{$cluster->id}", ['state' => 'active'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'cluster.tld_conflict');

        expect($cluster->refresh()->state)
            ->toBe(ClusterState::Inactive)
            ->and($matching->refresh()->cluster_id)
            ->toBeNull();

        $this
            ->putJson("/api/v1/clusters/{$cluster->id}/nodes/{$matching->id}")
            ->assertOk();
        $this
            ->patchJson("/api/v1/clusters/{$cluster->id}", ['state' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.tld', 'beast');

        expect($matching->refresh()->tld)
            ->toBe('beast')
            ->and($matching->cluster_id)
            ->toBe($cluster->id);

        $outside = Node::query()->create([
            'name' => 'outside',
            'tld' => 'orbit',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.5',
            'wireguard_ip' => '10.44.0.5',
        ]);

        $this
            ->patchJson("/api/v1/clusters/{$cluster->id}", ['tld' => 'orbit'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'cluster.tld_conflict');

        expect($cluster->refresh()->tld)
            ->toBe('beast')
            ->and($outside->refresh()->cluster_id)
            ->toBeNull();
    });
});
