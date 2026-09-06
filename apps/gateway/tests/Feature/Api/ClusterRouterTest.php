<?php

declare(strict_types=1);

use App\Actions\Clusters\ClearClusterRouterAction;
use App\Actions\Clusters\SetClusterRouterAction;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;

beforeEach(function (): void {
    $this->baselines = new class implements RoleBaselineConverger {
        /** @var list<string> */
        public array $calls = [];

        public bool $failConverge = false;

        public bool $failRemove = false;

        public function converge(Node $node, NodeRole $assignment): void
        {
            $this->calls[] = "converge:{$node->id}";

            if ($this->failConverge) {
                throw new RuntimeException('convergence failed');
            }
        }

        public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
        {
            $this->calls[] = "remove:{$node->id}";

            if ($this->failRemove) {
                throw new RuntimeException('removal failed');
            }
        }

        public function removeUnreachable(Node $node, NodeRole $assignment): void {}
    };
    app()->instance(RoleBaselineConverger::class, $this->baselines);
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

it('retains a failed initial Router convergence and resumes an identical set', function (): void {
    $this->baselines->failConverge = true;

    expect(fn () => app(SetClusterRouterAction::class)->execute($this->cluster, $this->first))
        ->toThrow(RuntimeException::class, 'convergence failed');

    $assignment = $this->first->roles()->where('role', RoleName::Router)->sole();
    expect($assignment->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($assignment->failed_step)
        ->toBe('converge:baseline')
        ->and($this->cluster->routerAssignment()->exists())
        ->toBeFalse();

    $this->baselines->failConverge = false;
    app(SetClusterRouterAction::class)->execute($this->cluster, $this->first);

    expect($assignment->refresh()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->baselines->calls)
        ->toBe([
            "converge:{$this->first->id}",
            "converge:{$this->first->id}",
        ]);
});

it('keeps the active Router when replacement convergence fails', function (): void {
    app(SetClusterRouterAction::class)->execute($this->cluster, $this->first);
    $this->baselines->failConverge = true;

    expect(fn () => app(SetClusterRouterAction::class)->execute($this->cluster, $this->second))
        ->toThrow(RuntimeException::class, 'convergence failed');

    expect($this->cluster->routerAssignment()->sole()->node_id)
        ->toBe($this->first->id)
        ->and($this->second->roles()->where('role', RoleName::Router)->sole()->status)
        ->toBe(LifecycleStatus::Failed);
});

it('retains failed old Router cleanup with the replacement active and resumes it', function (): void {
    app(SetClusterRouterAction::class)->execute($this->cluster, $this->first);
    $this->baselines->failRemove = true;

    expect(fn () => app(SetClusterRouterAction::class)->execute($this->cluster, $this->second))
        ->toThrow(RuntimeException::class, 'removal failed');

    expect($this->cluster->routerAssignment()->sole()->node_id)
        ->toBe($this->second->id)
        ->and($this->first->roles()->where('role', RoleName::Router)->sole()->failed_step)
        ->toBe('remove:baseline');

    $this->baselines->failRemove = false;
    app(SetClusterRouterAction::class)->execute($this->cluster, $this->second);

    expect(NodeRole::query()->where('role', RoleName::Router)->sole()->node_id)->toBe($this->second->id);
});

it('retains failed Router clear cleanup and removes every retained assignment on retry', function (): void {
    app(SetClusterRouterAction::class)->execute($this->cluster, $this->first);
    $active = $this->cluster->routerAssignment()->sole();
    $this->second
        ->roles()
        ->create([
            'cluster_id' => $this->cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Failed,
            'failed_step' => 'remove:baseline',
            'error_code' => 'test.failure',
        ]);
    $this->baselines->failRemove = true;

    expect(fn () => app(ClearClusterRouterAction::class)->execute($this->cluster))
        ->toThrow(RuntimeException::class, 'removal failed');
    expect($active->refresh()->status)->toBe(LifecycleStatus::Active);

    $this->baselines->failRemove = false;
    app(ClearClusterRouterAction::class)->execute($this->cluster);

    expect(NodeRole::query()->where('role', RoleName::Router)->exists())->toBeFalse();
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

it('refuses to clear or detach an active TLD-bearing Cluster Router until the Cluster is inactive', function (): void {
    $this->cluster->update(['tld' => 'beast']);
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

it('clears an optional Router while a TLD-less Cluster remains active', function (): void {
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertOk();
    $this->patchJson("/api/v1/clusters/{$this->cluster->id}", ['state' => 'active'])->assertOk();

    $this
        ->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true])
        ->assertOk()
        ->assertJsonPath('data.state', 'active')
        ->assertJsonPath('data.tld', null)
        ->assertJsonPath('data.router', null);

    expect($this->cluster->refresh()->state->value)
        ->toBe('active')
        ->and($this->cluster->routerAssignment()->exists())
        ->toBeFalse();
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
