<?php

declare(strict_types=1);

use App\Actions\Clusters\ClearClusterRouterAction;
use App\Actions\Clusters\SetClusterRouterAction;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->baselines = new class implements RoleBaselineConverger {
        /** @var list<string> */
        public array $calls = [];

        public bool $failConverge = false;

        public bool $failRemove = false;

        public ?int $failRemoveNodeId = null;

        public ?Closure $observe = null;

        public function converge(Node $node, NodeRole $assignment): void
        {
            ($this->observe ?? static function (): void {})();
            $this->calls[] = "converge:{$node->id}";

            if ($this->failConverge) {
                throw new RuntimeException('convergence failed');
            }
        }

        public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
        {
            ($this->observe ?? static function (): void {})();
            $this->calls[] = "remove:{$node->id}";

            if ($this->failRemove || $this->failRemoveNodeId === $node->id) {
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

it('returns 409 before Router validation or mutation while the Cluster owner is busy', function (): void {
    $outside = cluster_router_api_node('busy-outside', '10.44.0.4');
    $candidate = $this->first
        ->roles()
        ->create([
            'cluster_id' => $this->cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Provisioning,
        ]);
    app()->instance(ClusterRouterOperationLock::class, new class implements ClusterRouterOperationLock {
        public function run(int $clusterId, Closure $operation): mixed
        {
            throw new ResourceOperationException(
                errorCode: 'cluster.router_busy',
                message: 'Another Cluster Router operation is active. Retry the request.',
                status: 409,
            );
        }
    });

    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$outside->id}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'cluster.router_busy');
    $this
        ->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true])
        ->assertConflict()
        ->assertJsonPath('error.code', 'cluster.router_busy');

    expect($candidate->fresh()->status)
        ->toBe(LifecycleStatus::Provisioning)
        ->and($this->baselines->calls)
        ->toBeEmpty();

    app()->instance(ClusterRouterOperationLock::class, new ClusterRouterApiOperationLock);
    $this
        ->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true])
        ->assertOk();

    expect($candidate->fresh())->toBeNull();
});

it('reloads Router assignments after waiting before a replacement', function (): void {
    $owner = new ClusterRouterApiOperationLock(function (): void {
        $this->first
            ->roles()
            ->create([
                'cluster_id' => $this->cluster->id,
                'role' => RoleName::Router,
                'status' => LifecycleStatus::Active,
            ]);
    });
    app()->instance(ClusterRouterOperationLock::class, $owner);

    app(SetClusterRouterAction::class)->execute($this->cluster, $this->second);

    expect($this->cluster->routerAssignment()->sole()->node_id)
        ->toBe($this->second->id)
        ->and($this->baselines->calls)
        ->toBe([
            "converge:{$this->second->id}",
            "remove:{$this->first->id}",
        ]);
});

it('reloads the requested Node after waiting and rejects stale membership', function (): void {
    $owner = new ClusterRouterApiOperationLock(function (): void {
        $this->second->update(['cluster_id' => null]);
    });
    app()->instance(ClusterRouterOperationLock::class, $owner);

    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'cluster.router_node_invalid');

    expect($this->second->roles()->where('role', RoleName::Router)->exists())
        ->toBeFalse()
        ->and($this->baselines->calls)
        ->toBeEmpty();
});

it('runs Router mutation and removal guards against state created while waiting', function (): void {
    $owner = new ClusterRouterApiOperationLock(function (): void {
        $app = OrbitApp::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'repository_url' => 'https://example.test/acme.git',
            'default_branch' => 'main',
            'root' => 'public',
        ]);
        $this->first
            ->roles()
            ->create([
                'cluster_id' => $this->cluster->id,
                'role' => RoleName::Router,
                'status' => LifecycleStatus::Active,
            ]);
        $target = AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $this->first->id,
            'name' => 'default',
            'checkout_path' => '/srv/acme/default',
        ]);
        $route = Route::query()->create([
            'app_id' => $app->id,
            'cluster_id' => $this->cluster->id,
            'hostname' => 'acme.example.test',
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Private,
        ]);
        $route->targets()->create(['app_instance_id' => $target->id, 'position' => 0]);
        $route->update(['status' => RouteStatus::Active]);
        $target->update(['status' => AppInstanceState::Active]);
    });
    app()->instance(ClusterRouterOperationLock::class, $owner);

    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.reconciliation_required');
    $this
        ->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.reconciliation_required');

    expect($this->cluster->routerAssignment()->sole()->node_id)
        ->toBe($this->first->id)
        ->and($this->baselines->calls)
        ->toBeEmpty();
});

it('holds Router ownership through baseline work outside database transactions', function (): void {
    $owner = new ClusterRouterApiOperationLock;
    app()->instance(ClusterRouterOperationLock::class, $owner);
    $transactionLevel = DB::transactionLevel();
    $this->baselines->observe = static function () use ($owner, $transactionLevel): void {
        expect($owner->active)
            ->toBeTrue()
            ->and(DB::transactionLevel())
            ->toBe($transactionLevel);
    };

    app(SetClusterRouterAction::class)->execute($this->cluster, $this->first);
    app(ClearClusterRouterAction::class)->execute($this->cluster);

    expect($owner->clusterIds)
        ->toBe([$this->cluster->id, $this->cluster->id])
        ->and($this->baselines->calls)
        ->toBe([
            "converge:{$this->first->id}",
            "remove:{$this->first->id}",
        ]);
});

it('removes every obsolete Router after several replacement convergence failures', function (): void {
    $third = cluster_router_api_node('third-router', '10.44.0.4', $this->cluster);
    $current = cluster_router_api_node('current-router', '10.44.0.5', $this->cluster);
    app(SetClusterRouterAction::class)->execute($this->cluster, $this->first);
    $this->baselines->failConverge = true;

    expect(fn () => app(SetClusterRouterAction::class)->execute($this->cluster, $this->second))
        ->toThrow(RuntimeException::class, 'convergence failed');
    expect(fn () => app(SetClusterRouterAction::class)->execute($this->cluster, $third))
        ->toThrow(RuntimeException::class, 'convergence failed');

    $this->baselines->failConverge = false;
    app(SetClusterRouterAction::class)->execute($this->cluster, $current);

    expect(NodeRole::query()->where('role', RoleName::Router)->sole()->node_id)
        ->toBe($current->id)
        ->and($this->baselines->calls)
        ->toBe([
            "converge:{$this->first->id}",
            "converge:{$this->second->id}",
            "converge:{$third->id}",
            "converge:{$current->id}",
            "remove:{$this->first->id}",
            "remove:{$this->second->id}",
            "remove:{$third->id}",
        ]);
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

it('preserves cleanup progress and finishes the remainder on an identical set', function (): void {
    $unprocessed = cluster_router_api_node('unprocessed-router', '10.44.0.4', $this->cluster);
    $current = cluster_router_api_node('current-router', '10.44.0.5', $this->cluster);
    app(SetClusterRouterAction::class)->execute($this->cluster, $this->first);
    $failed = $this->second
        ->roles()
        ->create([
            'cluster_id' => $this->cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Failed,
            'failed_step' => 'converge:baseline',
            'error_code' => 'test.failed',
        ]);
    $retained = $unprocessed
        ->roles()
        ->create([
            'cluster_id' => $this->cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Failed,
            'failed_step' => 'converge:baseline',
            'error_code' => 'test.unprocessed',
        ]);
    $this->baselines->failRemoveNodeId = $this->second->id;

    expect(fn () => app(SetClusterRouterAction::class)->execute($this->cluster, $current))
        ->toThrow(RuntimeException::class, 'removal failed');

    expect($this->cluster->routerAssignment()->sole()->node_id)
        ->toBe($current->id)
        ->and($this->first->roles()->where('role', RoleName::Router)->exists())
        ->toBeFalse()
        ->and($failed->refresh()->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($failed->failed_step)
        ->toBe('remove:baseline')
        ->and($failed->error_code)
        ->toBe('node_role.operation_failed')
        ->and($retained->refresh()->failed_step)
        ->toBe('converge:baseline')
        ->and($retained->error_code)
        ->toBe('test.unprocessed');

    $this->baselines->failRemoveNodeId = null;
    app(SetClusterRouterAction::class)->execute($this->cluster, $current);

    expect(NodeRole::query()->where('role', RoleName::Router)->sole()->node_id)
        ->toBe($current->id)
        ->and($this->baselines->calls)
        ->toBe([
            "converge:{$this->first->id}",
            "converge:{$current->id}",
            "remove:{$this->first->id}",
            "remove:{$this->second->id}",
            "remove:{$this->second->id}",
            "remove:{$unprocessed->id}",
        ]);

    $this
        ->deleteJson(
            "/api/v1/clusters/{$this->cluster->id}/nodes/{$this->first->id}",
            ['force' => true],
        )
        ->assertOk();

    expect($this->first->refresh()->cluster_id)->toBeNull();
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

final class ClusterRouterApiOperationLock implements ClusterRouterOperationLock
{
    /** @var list<int> */
    public array $clusterIds = [];

    public bool $active = false;

    private bool $prepared = false;

    public function __construct(
        private readonly ?Closure $before = null,
    ) {}

    public function run(int $clusterId, Closure $operation): mixed
    {
        $this->clusterIds[] = $clusterId;

        if (! $this->prepared) {
            ($this->before ?? static function (): void {})();
            $this->prepared = true;
        }

        $this->active = true;

        try {
            return $operation();
        } finally {
            $this->active = false;
        }
    }
}
