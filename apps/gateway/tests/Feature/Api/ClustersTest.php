<?php

declare(strict_types=1);

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

    it('rejects activation without an active Router and preserves the inactive state', function (): void {
        $cluster = Cluster::query()->create([
            'name' => 'development',
            'state' => 'inactive',
        ]);

        $this
            ->patchJson("/api/v1/clusters/{$cluster->id}", ['state' => 'active'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'cluster.router_required');

        expect($cluster->refresh()->state->value)->toBe('inactive');
    });
});
