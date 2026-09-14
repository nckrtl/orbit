<?php

declare(strict_types=1);

use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeClusterRouterDnsSelectionReconciler;

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

        $createRequestId = (string) Str::uuid();
        $withoutTld = $this
            ->withHeader('X-Orbit-Request-Id', $createRequestId)
            ->postJson('/api/v1/clusters', ['name' => 'no-tld'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'no-tld')
            ->assertJsonPath('data.tld', null)
            ->assertJsonPath('data.state', 'inactive');
        expect(Activity::query()->where('request_id', $createRequestId)->sole()->command)
            ->toBe('cluster:create');

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

        $destroyRequestId = (string) Str::uuid();
        $this
            ->withHeader('X-Orbit-Request-Id', $destroyRequestId)
            ->deleteJson("/api/v1/clusters/{$clusterId}")
            ->assertOk()
            ->assertJsonPath('data.id', $clusterId);

        expect(Cluster::query()->find($clusterId))
            ->toBeNull()
            ->and(Activity::query()->where('request_id', $destroyRequestId)->sole()->command)
            ->toBe('cluster:destroy');
    });

    it('refuses removal for member Nodes before owned Routes whatever the Route status', function (): void {
        $cluster = Cluster::query()->create(['name' => 'routing']);
        $member = Node::query()->create([
            'cluster_id' => $cluster->id,
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'wireguard_ip' => '10.44.0.10',
        ]);
        $app = OrbitApp::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'repository_url' => 'https://example.test/acme.git',
            'default_branch' => 'main',
            'root' => 'public',
        ]);
        $instance = AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $member->id,
            'name' => 'dev',
            'checkout_path' => '/srv/orbit/apps/acme/dev',
            'branch' => 'dev',
            'starting_commit' => str_repeat('a', 40),
            'status' => AppInstanceState::Active,
        ]);
        $route = Route::query()->create([
            'app_id' => $app->id,
            'cluster_id' => $cluster->id,
            'hostname' => 'acme.example.test',
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Private,
        ]);
        $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => RouteStatus::Active]);

        $this
            ->deleteJson("/api/v1/clusters/{$cluster->id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'cluster.not_empty');

        $member->update(['cluster_id' => null]);

        $this
            ->deleteJson("/api/v1/clusters/{$cluster->id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'cluster.has_routes');

        expect($cluster->fresh())
            ->not
            ->toBeNull()
            ->and($route->refresh()->status)
            ->toBe(RouteStatus::Active)
            ->and($route->cluster_id)
            ->toBe($cluster->id);
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

    it('reconciles Router DNS selection before Cluster activation becomes authoritative', function (): void {
        $cluster = Cluster::query()->create([
            'name' => 'dns-activation',
            'state' => ClusterState::Inactive,
        ]);
        $dns = clusters_dns_reconciler();
        $dns->onExpand = static function () use ($cluster): void {
            expect($cluster->fresh()?->state)->toBe(ClusterState::Inactive);
        };

        $this
            ->patchJson("/api/v1/clusters/{$cluster->id}", ['state' => 'active'])
            ->assertOk();

        expect($cluster->refresh()->state)
            ->toBe(ClusterState::Active)
            ->and(array_column($dns->events, 'phase'))
            ->toBe(['expand', 'prune'])
            ->and($dns->events[0]['clusterOverrides'][$cluster->id]['state'])
            ->toBe(ClusterState::Active);
    });

    it('leaves Cluster state unchanged when DNS selection expansion fails', function (): void {
        $cluster = Cluster::query()->create([
            'name' => 'dns-activation-failure',
            'state' => ClusterState::Inactive,
        ]);
        $dns = clusters_dns_reconciler();
        $dns->expandFailure = new RuntimeConvergenceException(
            step: 'private-dns',
            errorCode: 'app-dev.dns_config_failed',
            message: 'DNS selection failed.',
        );

        $this
            ->patchJson("/api/v1/clusters/{$cluster->id}", ['state' => 'active'])
            ->assertServerError();

        expect($cluster->refresh()->state)
            ->toBe(ClusterState::Inactive)
            ->and(array_column($dns->events, 'phase'))
            ->toBe(['expand']);
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
        $dns = clusters_dns_reconciler();

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
            ->toBeNull()
            ->and(array_column($dns->events, 'phase'))
            ->toBe(['expand', 'prune', 'expand', 'prune']);
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

    it('owns state and TLD updates outside their transaction and leaves name-only updates independent', function (): void {
        $cluster = Cluster::query()->create([
            'name' => 'development',
            'state' => ClusterState::Inactive,
        ]);
        $transactionLevel = DB::transactionLevel();
        $owner = new ClusterUpdateRouterOperationLock;
        app()->instance(ClusterRouterOperationLock::class, $owner);

        $this
            ->patchJson("/api/v1/clusters/{$cluster->id}", ['name' => 'renamed'])
            ->assertOk();
        $this
            ->patchJson("/api/v1/clusters/{$cluster->id}", ['tld' => 'beast'])
            ->assertOk();
        $this
            ->patchJson("/api/v1/clusters/{$cluster->id}", ['state' => 'inactive'])
            ->assertOk();

        expect($owner->entries)
            ->toBe([
                ['cluster_id' => $cluster->id, 'transaction_level' => $transactionLevel],
                ['cluster_id' => $cluster->id, 'transaction_level' => $transactionLevel],
            ])
            ->and($cluster->refresh()->only(['name', 'tld']))
            ->toBe(['name' => 'renamed', 'tld' => 'beast']);
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

function clusters_dns_reconciler(): FakeClusterRouterDnsSelectionReconciler
{
    $dns = app(ClusterRouterDnsSelectionReconciler::class);
    assert($dns instanceof FakeClusterRouterDnsSelectionReconciler);

    return $dns;
}

final class ClusterUpdateRouterOperationLock implements ClusterRouterOperationLock
{
    /** @var list<array{cluster_id: int, transaction_level: int}> */
    public array $entries = [];

    public function run(int $clusterId, Closure $operation): mixed
    {
        $this->entries[] = [
            'cluster_id' => $clusterId,
            'transaction_level' => DB::transactionLevel(),
        ];

        return $operation();
    }
}
