<?php

declare(strict_types=1);

use App\Actions\Clusters\ClearClusterRouterAction;
use App\Actions\Clusters\SetClusterRouterAction;
use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterReplacementProjector;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeClusterRouterDnsSelectionReconciler;
use Tests\Support\FakeClusterRouterReplacementProjector;

beforeEach(function (): void {
    $this->baselines = new class implements RoleBaselineConverger
    {
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

        public function removeUnreachable(Node $node, NodeRole $assignment): void
        {
            $this->calls[] = "removeUnreachable:{$node->id}";
        }
    };
    app()->instance(RoleBaselineConverger::class, $this->baselines);
    $this->replacements = cluster_router_replacement_projector();
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

it('reconciles Router DNS selection before a new Router assignment becomes authoritative', function (): void {
    $dns = cluster_router_dns_reconciler();
    $dns->onExpand = function (): void {
        expect($this->cluster->routerAssignment()->exists())->toBeFalse();
    };

    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")
        ->assertOk();

    expect($this->cluster->routerAssignment()->sole()->node_id)
        ->toBe($this->first->id)
        ->and(array_column($dns->events, 'phase'))
        ->toBe(['expand', 'prune'])
        ->and($dns->events[0]['clusterOverrides'][$this->cluster->id]['router_node_id'])
        ->toBe($this->first->id);
});

it('keeps the previous Router when DNS selection expansion fails', function (): void {
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertOk();
    $dns = cluster_router_dns_reconciler();
    $dns->events = [];
    $dns->expandFailure = new RuntimeConvergenceException(
        step: 'private-dns',
        errorCode: 'app-dev.dns_config_failed',
        message: 'DNS selection failed.',
    );

    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")
        ->assertServerError();

    expect($this->cluster->routerAssignment()->sole()->node_id)
        ->toBe($this->first->id)
        ->and(NodeRole::query()->where('role', RoleName::Router)->where('node_id', $this->second->id)->exists())
        ->toBeFalse()
        ->and(array_column($dns->events, 'phase'))
        ->toBe(['expand']);
});

it('sets a Router beside an existing database role', function (): void {
    $this->second->roles()->create([
        'role' => RoleName::Database,
        'status' => LifecycleStatus::Active,
    ]);

    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")
        ->assertOk()
        ->assertJsonPath('data.router.id', $this->second->id);

    expect($this->second->refresh()->roles->pluck('role')->map->value->sort()->values()->all())
        ->toBe(['database', 'router'])
        ->and($this->baselines->calls)
        ->toBe(["converge:{$this->second->id}"]);
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
    app()->instance(ClusterRouterOperationLock::class, new class implements ClusterRouterOperationLock
    {
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
    $requestId = (string) Str::uuid();
    $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true])
        ->assertOk();

    expect($candidate->fresh())
        ->toBeNull()
        ->and(Activity::query()->where('request_id', $requestId)->sole()->command)
        ->toBe('cluster:router:unset');
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
            'domain' => 'acme.example.test',
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Private,
        ]);
        $route->targets()->create(['app_instance_id' => $target->id, 'position' => 0]);
        $route->update(['status' => RouteStatus::Active]);
        $target->update(['status' => AppInstanceState::Active]);
    });
    app()->instance(ClusterRouterOperationLock::class, $owner);

    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")
        ->assertOk()
        ->assertJsonPath('data.router.id', $this->second->id);
    $this
        ->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.reconciliation_required');

    expect($this->cluster->routerAssignment()->sole()->node_id)
        ->toBe($this->second->id)
        ->and($this->baselines->calls)
        ->toBe([
            "converge:{$this->second->id}",
            "remove:{$this->first->id}",
        ])
        ->and($this->replacements->events)
        ->toContain('router-certificate')
        ->toContain('dns-publication')
        ->toContain('cleanup');
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
        ]);
});

it('rolls back a failed initial Router convergence and retries an identical set', function (): void {
    $this->baselines->failConverge = true;

    expect(fn () => app(SetClusterRouterAction::class)->execute($this->cluster, $this->first))
        ->toThrow(RuntimeException::class, 'convergence failed');

    expect($this->first->roles()->where('role', RoleName::Router)->exists())
        ->toBeFalse()
        ->and($this->cluster->routerAssignment()->exists())
        ->toBeFalse();

    $this->baselines->failConverge = false;
    app(SetClusterRouterAction::class)->execute($this->cluster, $this->first);

    expect($this->cluster->routerAssignment()->sole()->node_id)
        ->toBe($this->first->id)
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
        ->and($this->second->roles()->where('role', RoleName::Router)->exists())
        ->toBeFalse();
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
            'failed_step' => 'remove:baseline',
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
            "removeUnreachable:{$unprocessed->id}",
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

it('lets cluster show and detach succeed after a failed initial Router set', function (): void {
    $this->baselines->failConverge = true;

    expect(fn () => app(SetClusterRouterAction::class)->execute($this->cluster, $this->first))
        ->toThrow(RuntimeException::class, 'convergence failed');

    $this
        ->getJson("/api/v1/clusters/{$this->cluster->id}")
        ->assertOk()
        ->assertJsonPath('data.router', null);

    $this
        ->deleteJson(
            "/api/v1/clusters/{$this->cluster->id}/nodes/{$this->first->id}",
            ['force' => true],
        )
        ->assertOk()
        ->assertJsonPath('data.router', null);

    expect($this->first->refresh()->cluster_id)
        ->toBeNull()
        ->and($this->first->roles()->where('role', RoleName::Router)->exists())
        ->toBeFalse();
});

it('clears a leftover never-activated Router without live baseline removal', function (): void {
    $this->baselines->failRemove = true;
    $this->first
        ->roles()
        ->create([
            'cluster_id' => $this->cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Failed,
            'failed_step' => 'converge:baseline',
            'error_code' => 'node.managed_user_unavailable',
        ]);

    $this
        ->getJson("/api/v1/clusters/{$this->cluster->id}")
        ->assertOk()
        ->assertJsonPath('data.router', null);

    $this
        ->deleteJson("/api/v1/clusters/{$this->cluster->id}/router", ['force' => true])
        ->assertOk()
        ->assertJsonPath('data.router', null);

    expect(NodeRole::query()->where('role', RoleName::Router)->exists())
        ->toBeFalse()
        ->and($this->baselines->calls)
        ->toBe(["removeUnreachable:{$this->first->id}"]);
});

it('detaches a member that only has a leftover never-activated Router assignment', function (): void {
    $this->first
        ->roles()
        ->create([
            'cluster_id' => $this->cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Failed,
            'failed_step' => 'converge:baseline',
            'error_code' => 'node_role.convergence_failed',
        ]);

    $this
        ->getJson("/api/v1/clusters/{$this->cluster->id}")
        ->assertOk()
        ->assertJsonPath('data.router', null);

    $this
        ->deleteJson(
            "/api/v1/clusters/{$this->cluster->id}/nodes/{$this->first->id}",
            ['force' => true],
        )
        ->assertOk()
        ->assertJsonPath('data.router', null);

    expect($this->first->refresh()->cluster_id)
        ->toBeNull()
        ->and($this->first->roles()->where('role', RoleName::Router)->exists())
        ->toBeFalse()
        ->and($this->baselines->calls)
        ->toBe([]);
});

it('still forbids detaching an active Cluster Router or a failed live removal', function (): void {
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertOk();

    $this
        ->deleteJson(
            "/api/v1/clusters/{$this->cluster->id}/nodes/{$this->first->id}",
            ['force' => true],
        )
        ->assertConflict()
        ->assertJsonPath('error.code', 'cluster.router_detach_forbidden');

    $this->first->roles()->where('role', RoleName::Router)->update([
        'status' => LifecycleStatus::Failed,
        'failed_step' => 'remove:baseline',
        'error_code' => 'node_role.remove_failed',
    ]);

    $this
        ->deleteJson(
            "/api/v1/clusters/{$this->cluster->id}/nodes/{$this->first->id}",
            ['force' => true],
        )
        ->assertConflict()
        ->assertJsonPath('error.code', 'cluster.router_detach_forbidden');

    expect($this->first->refresh()->cluster_id)->toBe($this->cluster->id);
});

it('does not publish DNS selection for a refused Router assignment', function (): void {
    $outside = cluster_router_api_node('outside-dns', '10.44.0.8');
    $dns = cluster_router_dns_reconciler();

    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$outside->id}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'cluster.router_node_invalid');

    expect($dns->events)
        ->toBeEmpty()
        ->and($this->cluster->routerAssignment()->exists())
        ->toBeFalse();
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

it('prepares and publishes Cluster Route projections before a replacement assignment is authoritative', function (): void {
    [$route, $target] = cluster_router_owned_route($this->cluster, $this->first);
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertOk();
    $this->replacements->events = [];
    $this->replacements->placements = [];
    $this->replacements->onPrepare = function () use ($route, $target): void {
        expect($this->cluster->routerAssignment()->sole()->node_id)
            ->toBe($this->first->id)
            ->and($route->refresh()->only(['id', 'domain', 'cluster_id', 'node_id']))
            ->toBe([
                'id' => $route->id,
                'domain' => 'acme.example.test',
                'cluster_id' => $this->cluster->id,
                'node_id' => null,
            ])
            ->and($target->refresh()->node_id)
            ->toBe($this->first->id);
    };

    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")
        ->assertOk()
        ->assertJsonPath('data.router.id', $this->second->id);

    expect($route->refresh()->only(['id', 'domain', 'cluster_id', 'node_id', 'status']))
        ->toBe([
            'id' => $route->id,
            'domain' => 'acme.example.test',
            'cluster_id' => $this->cluster->id,
            'node_id' => null,
            'status' => RouteStatus::Active,
        ])
        ->and($target->refresh()->only(['id', 'node_id', 'status']))
        ->toBe([
            'id' => $target->id,
            'node_id' => $this->first->id,
            'status' => AppInstanceState::Active,
        ])
        ->and($this->replacements->events)
        ->toBe([
            'router-certificate',
            'firewall-policy',
            'workload-verify',
            'router-caddy',
            'dns-publication',
            'cleanup',
        ])
        ->and($this->replacements->placements[0]['router_id'])
        ->toBe($this->second->id);
});

it('composes a colocated replacement Caddy site without a self-proxy hop', function (): void {
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertOk();
    [$route] = cluster_router_owned_route($this->cluster, $this->second);
    $this->second->update(['lan_ip' => '10.10.0.3']);
    $sites = new AppDevSiteRepository()->forNode(
        $this->second,
        routerOverrides: [$this->cluster->id => $this->second->id],
    );
    $rendered = new AppDevCaddyConfigRenderer()->render($sites);

    expect($sites->contains(fn ($site): bool => $site->isProxy() && $site->domain === $route->domain))
        ->toBeFalse()
        ->and($rendered)
        ->not->toContain('reverse_proxy https://10.10.0.3')
        ->not->toContain('reverse_proxy https://127.0.0.1')
        ->not->toContain("reverse_proxy https://{$this->second->wireguard_ip}");
});

it('uses a local next hop when replacement colocates Router and workload roles', function (): void {
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertOk();
    [$route] = cluster_router_owned_route($this->cluster, $this->second);

    app(SetClusterRouterAction::class)->execute($this->cluster, $this->second);

    expect($this->replacements->events)
        ->toContain('router-caddy:local-next-hop')
        ->not->toContain('router-caddy')
        ->and($route->refresh()->domain)
        ->toBe('acme.example.test');
});

it('restores the old Router when replacement publication fails and retries the same candidate', function (): void {
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertOk();
    [$route] = cluster_router_owned_route($this->cluster, $this->first);
    $this->replacements->failures = ['dns-publication' => 1];

    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")
        ->assertServerError();

    $candidate = NodeRole::query()
        ->where('role', RoleName::Router)
        ->where('node_id', $this->second->id)
        ->sole();

    expect($this->cluster->routerAssignment()->sole()->node_id)
        ->toBe($this->first->id)
        ->and($candidate->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($candidate->failed_step)
        ->toBe('dns-publication')
        ->and($candidate->error_code)
        ->toBe('route.test_dns-publication')
        ->and($this->replacements->events)
        ->toContain('restore')
        ->and($route->refresh()->domain)
        ->toBe('acme.example.test');

    expect(fn () => app(SetClusterRouterAction::class)->execute($this->cluster, $this->first))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('cluster.router_transition_conflict');
        });

    $this->replacements->failures = [];
    $this->replacements->events = [];
    app(SetClusterRouterAction::class)->execute($this->cluster, $this->second);

    expect($this->cluster->routerAssignment()->sole()->node_id)
        ->toBe($this->second->id)
        ->and($this->replacements->events)
        ->toBe([
            'router-certificate',
            'firewall-policy',
            'workload-verify',
            'router-caddy',
            'dns-publication',
            'cleanup',
        ]);
});

it('keeps the replacement assignment when cleanup fails and refuses a conflicting transition', function (): void {
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertOk();
    cluster_router_owned_route($this->cluster, $this->first);
    $this->replacements->failures = ['cleanup' => 1];

    $this
        ->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->second->id}")
        ->assertServerError();

    $candidate = NodeRole::query()
        ->where('role', RoleName::Router)
        ->where('node_id', $this->second->id)
        ->sole();

    expect($this->cluster->routerAssignment()->sole()->node_id)
        ->toBe($this->second->id)
        ->and($candidate->status)
        ->toBe(LifecycleStatus::Active)
        ->and($candidate->failed_step)
        ->toBe('cleanup');

    expect(fn () => app(SetClusterRouterAction::class)->execute($this->cluster, $this->first))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('cluster.router_transition_conflict');
        });
});

it('replaces a Router when the application returns HTTP 500 and leaves Route lifecycle unchanged', function (): void {
    $this->putJson("/api/v1/clusters/{$this->cluster->id}/router/{$this->first->id}")->assertOk();
    [$route, $target] = cluster_router_owned_route($this->cluster, $this->first);
    $this->replacements->applicationHttpStatus = 500;

    app(SetClusterRouterAction::class)->execute($this->cluster, $this->second);

    expect($this->cluster->routerAssignment()->sole()->node_id)
        ->toBe($this->second->id)
        ->and($route->refresh()->status)
        ->toBe(RouteStatus::Active)
        ->and($target->refresh()->status)
        ->toBe(AppInstanceState::Active)
        ->and($this->replacements->events)
        ->toContain('workload-verify');
});

function cluster_router_replacement_projector(): FakeClusterRouterReplacementProjector
{
    $projector = new FakeClusterRouterReplacementProjector;
    app()->instance(ClusterRouterReplacementProjector::class, $projector);

    return $projector;
}

/**
 * @return array{0: Route, 1: AppInstance}
 */
function cluster_router_owned_route(Cluster $cluster, Node $workload): array
{
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme-'.Str::random(6),
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $target = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $workload->id,
        'name' => 'default',
        'checkout_path' => '/srv/acme/default',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'domain' => 'acme.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $target->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return [$route->refresh(), $target];
}

function cluster_router_dns_reconciler(): FakeClusterRouterDnsSelectionReconciler
{
    $dns = app(ClusterRouterDnsSelectionReconciler::class);
    assert($dns instanceof FakeClusterRouterDnsSelectionReconciler);

    return $dns;
}

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
