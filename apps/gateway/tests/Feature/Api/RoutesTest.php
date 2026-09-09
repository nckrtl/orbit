<?php

declare(strict_types=1);

use App\Actions\Clusters\AttachClusterNodeAction;
use App\Actions\Clusters\UpdateClusterAction;
use App\Actions\Routes\CreateRouteAction;
use App\Data\Clusters\UpdateClusterData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\CertificateMode;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteHostnameProjector;
use App\Domain\Routes\RoutePublication;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteTarget;
use App\Models\Workspace;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->node = route_node('dev-one', '10.44.0.2', 'one.test');
    $this->target = route_instance($this->orbitApp, $this->node, 'main');
});

it('creates, retries, lists, shows, updates, clears, and removes an explicit Route', function (): void {
    $requestId = (string) Str::uuid();
    $payload = [
        'app_id' => $this->orbitApp->id,
        'hostname' => ' App.Example.Test ',
        'publication' => 'private',
        'app_instance_id' => $this->target->id,
    ];

    $created = $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson('/api/v1/routes', $payload)
        ->assertCreated()
        ->assertJsonPath('data.app_id', $this->orbitApp->id)
        ->assertJsonPath('data.node_id', $this->node->id)
        ->assertJsonPath('data.cluster_id', null)
        ->assertJsonPath('data.generation_basis_node_id', null)
        ->assertJsonPath('data.hostname', 'app.example.test')
        ->assertJsonPath('data.provenance', 'explicit')
        ->assertJsonPath('data.publication', 'private')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.failed_step', null)
        ->assertJsonPath('data.error_code', null)
        ->assertJsonPath('data.target.app_instance_id', $this->target->id)
        ->assertJsonPath('data.target.position', 0);
    $routeId = $created->json('data.id');

    $this
        ->postJson('/api/v1/routes', $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $routeId);
    expect(Route::query()->count())
        ->toBe(1)
        ->and(Activity::query()->where('request_id', $requestId)->firstOrFail()->subject_type)
        ->toBe(Route::class);

    $this->getJson('/api/v1/routes')->assertOk()->assertJsonPath('data.0.id', $routeId);
    $this->getJson("/api/v1/routes/{$routeId}")->assertOk()->assertJsonPath('data.id', $routeId);
    $this
        ->patchJson("/api/v1/routes/{$routeId}", [
            'hostname' => 'next.example.test',
            'publication' => 'public',
        ])
        ->assertOk()
        ->assertJsonPath('data.hostname', 'next.example.test')
        ->assertJsonPath('data.publication', 'public')
        ->assertJsonPath('data.status', 'pending');

    $this->target->update(['status' => AppInstanceState::Reserved]);
    $this->deleteJson("/api/v1/routes/{$routeId}/target")->assertOk()->assertJsonPath('data.target', null);
    expect(Route::query()->sole()->node_id)->toBe($this->node->id);

    $this->deleteJson("/api/v1/routes/{$routeId}")->assertOk();
    expect(Route::query()->count())->toBe(0)->and(RouteTarget::query()->count())->toBe(0);
});

it('creates targetless exclusive Node and active Cluster scopes', function (): void {
    $nodeRoute = $this->postJson('/api/v1/routes', [
        'app_id' => $this->orbitApp->id,
        'hostname' => 'node.example.test',
        'publication' => 'private',
        'node_id' => $this->node->id,
    ])->assertCreated();
    expect($nodeRoute->json('data.node_id'))->toBe($this->node->id)->and($nodeRoute->json('data.target'))->toBeNull();

    [$cluster] = route_cluster('active', 'cluster.test');
    $this
        ->postJson('/api/v1/routes', [
            'app_id' => $this->orbitApp->id,
            'hostname' => 'cluster.example.test',
            'publication' => 'public',
            'cluster_id' => $cluster->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.node_id', null)
        ->assertJsonPath('data.cluster_id', $cluster->id);
});

it('returns 409 before replacing or clearing an active target or removing its Route', function (): void {
    $route = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $route->update(['status' => 'active']);
    $otherNode = route_node('dev-two', '10.44.0.3', 'two.test');
    $other = route_instance($this->orbitApp, $otherNode, 'feature');
    $before = $route->fresh(['targets'])->toArray();
    $targetRowsBefore = route_api_target_rows();
    $message = "Active AppInstance [{$this->target->id}] must remain associated with Route [{$route->id}].";

    $this
        ->putJson("/api/v1/routes/{$route->id}/target", [
            'app_instance_id' => $other->id,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_conflict')
        ->assertJsonPath('error.message', $message);
    $this
        ->deleteJson("/api/v1/routes/{$route->id}/target")
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_conflict')
        ->assertJsonPath('error.message', $message);
    $this
        ->deleteJson("/api/v1/routes/{$route->id}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_conflict')
        ->assertJsonPath('error.message', $message);

    expect($route->fresh(['targets'])->toArray())
        ->toBe($before)
        ->and(route_api_target_rows())
        ->toBe($targetRowsBefore)
        ->and($this->target->fresh())
        ->not->toBeNull();
});

it('returns 409 with both Routes when the requested target belongs to another Route', function (): void {
    $existing = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $requested = $this->postJson('/api/v1/routes', [
        'app_id' => $this->orbitApp->id,
        'hostname' => 'fixed.example.test',
        'publication' => 'private',
        'node_id' => $this->node->id,
    ])->assertCreated();
    $requestedId = $requested->json('data.id');
    $routesBefore = route_api_routes();
    $targetRowsBefore = route_api_target_rows();

    $this
        ->putJson("/api/v1/routes/{$requestedId}/target", [
            'app_instance_id' => $this->target->id,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_conflict')
        ->assertJsonPath(
            'error.message',
            "AppInstance [{$this->target->id}] is already associated with Route [{$existing->id}] and cannot be assigned to Route [{$requestedId}].",
        );

    expect(route_api_routes())
        ->toBe($routesBefore)
        ->and(route_api_target_rows())
        ->toBe($targetRowsBefore);
});

it('keeps every Route association unchanged for exact target no-ops', function (): void {
    $targeted = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $targeted->update(['status' => 'active']);
    $empty = $this->postJson('/api/v1/routes', [
        'app_id' => $this->orbitApp->id,
        'hostname' => 'empty.example.test',
        'publication' => 'private',
        'node_id' => $this->node->id,
    ])->assertCreated();
    $emptyId = $empty->json('data.id');
    $routesBefore = route_api_routes();
    $targetRowsBefore = route_api_target_rows();

    $this
        ->putJson("/api/v1/routes/{$targeted->id}/target", [
            'app_instance_id' => $this->target->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.target.app_instance_id', $this->target->id);
    $this
        ->deleteJson("/api/v1/routes/{$emptyId}/target")
        ->assertOk()
        ->assertJsonPath('data.target', null);

    expect(route_api_routes())
        ->toBe($routesBefore)
        ->and(route_api_target_rows())
        ->toBe($targetRowsBefore);
});

it('leaves the complete Route unchanged for invalid target proposals', function (): void {
    $route = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $before = $route->fresh(['targets'])->toArray();
    $otherApp = OrbitApp::query()->create([
        'name' => 'Other',
        'slug' => 'other',
        'repository_url' => 'https://example.test/other.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $foreign = route_instance($otherApp, $this->node, 'foreign');

    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'app_instance_id' => $foreign->id,
    ])->assertConflict()->assertJsonPath('error.code', 'route.target_app_conflict');

    expect($route->fresh(['targets'])->toArray())->toBe($before);

    $inactive = route_instance($this->orbitApp, $this->node, 'inactive');
    $inactive->update(['status' => AppInstanceState::Reserved]);
    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'app_instance_id' => $inactive->id,
    ])->assertConflict()->assertJsonPath('error.code', 'route.target_inactive');
    expect($route->fresh(['targets'])->toArray())->toBe($before);

    $tldlessNode = route_node('tldless', '10.44.0.4', null);
    $tldless = route_instance($this->orbitApp, $tldlessNode, 'tldless');
    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'app_instance_id' => $tldless->id,
    ])->assertConflict()->assertJsonPath('error.code', 'route.tld_required');
    expect($route->fresh(['targets'])->toArray())->toBe($before);

    $routerlessCluster = Cluster::query()->create([
        'name' => 'routerless',
        'state' => ClusterState::Active,
        'tld' => null,
    ]);
    $clusterNode = route_node('clustered', '10.44.0.5', 'clustered.test');
    $clusterNode->update(['cluster_id' => $routerlessCluster->id]);
    $clustered = route_instance($this->orbitApp, $clusterNode, 'clustered');
    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'app_instance_id' => $clustered->id,
    ])->assertConflict()->assertJsonPath('error.code', 'route.router_required');
    expect($route->fresh(['targets'])->toArray())->toBe($before);

    $collisionNode = route_node('collision', '10.44.0.6', 'collision.test');
    $collision = route_instance($this->orbitApp, $collisionNode, 'feature');
    Route::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $collisionNode->id,
        'hostname' => 'feature.acme.collision.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'app_instance_id' => $collision->id,
    ])->assertConflict();
    expect($route->fresh(['targets'])->toArray())->toBe($before);
});

it('keeps legacy Instance and Workspace host identity unchanged through Route operations', function (): void {
    $legacy = Instance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'legacy',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/legacy/acme',
        'hostname' => 'legacy.example.test',
        'certificate_mode' => CertificateMode::OrbitCa,
        'status' => LifecycleStatus::Active,
    ]);
    $workspace = Workspace::query()->create([
        'instance_id' => $legacy->id,
        'name' => 'preview',
        'branch' => 'preview',
        'checkout_path' => '/srv/orbit/legacy/acme/preview',
        'hostname' => 'preview.example.test',
        'status' => LifecycleStatus::Active,
    ]);
    $legacyBefore = $legacy->only(['hostname', 'certificate_mode']);
    $workspaceBefore = $workspace->only(['hostname']);

    $route = $this->postJson('/api/v1/routes', [
        'app_id' => $this->orbitApp->id,
        'hostname' => 'route.example.test',
        'publication' => 'private',
        'node_id' => $this->node->id,
    ])->assertCreated();
    $routeId = $route->json('data.id');
    $this->patchJson("/api/v1/routes/{$routeId}", ['hostname' => 'changed.example.test'])->assertOk();
    $this->putJson("/api/v1/routes/{$routeId}/target", ['app_instance_id' => $this->target->id])->assertOk();
    $this->target->update(['status' => AppInstanceState::Reserved]);
    $this->deleteJson("/api/v1/routes/{$routeId}/target")->assertOk();

    $cluster = Cluster::query()->create(['name' => 'legacy-proof', 'state' => ClusterState::Inactive]);
    $router = route_node('legacy-router', '10.44.0.7', null);
    $router->update(['cluster_id' => $cluster->id]);
    $router
        ->roles()
        ->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);
    app(AttachClusterNodeAction::class)->execute($cluster, $this->node);
    app(UpdateClusterAction::class)->execute($cluster, new UpdateClusterData(
        nameProvided: false,
        name: null,
        tldProvided: false,
        tld: null,
        stateProvided: true,
        state: ClusterState::Active,
    ));
    $this->deleteJson("/api/v1/routes/{$routeId}")->assertOk();

    expect($legacy->refresh()->only(['hostname', 'certificate_mode']))
        ->toBe($legacyBefore)
        ->and($workspace->refresh()->only(['hostname']))
        ->toBe($workspaceBefore);
});

it('rejects malformed input, caller-owned fields, arrays, and conflicting retries unchanged', function (): void {
    foreach (['bad_name', '-bad.test', str_repeat('a', 254)] as $hostname) {
        $this->postJson('/api/v1/routes', [
            'app_id' => $this->orbitApp->id,
            'hostname' => $hostname,
            'publication' => 'private',
            'node_id' => $this->node->id,
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/routes', [
        'app_id' => $this->orbitApp->id,
        'hostname' => 'safe.test',
        'publication' => 'private',
        'node_id' => $this->node->id,
        'targets' => [$this->target->id],
        'status' => 'active',
    ])->assertUnprocessable();

    $generated = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $this
        ->deleteJson("/api/v1/routes/{$generated->id}/target", ['unsupported' => true])
        ->assertUnprocessable();
    expect($generated->targets()->count())->toBe(1);
    $this
        ->deleteJson("/api/v1/routes/{$generated->id}", ['unsupported' => true])
        ->assertUnprocessable();
    expect($generated->fresh())->not->toBeNull();

    $payload = [
        'app_id' => $this->orbitApp->id,
        'hostname' => 'retry.test',
        'publication' => 'private',
        'node_id' => $this->node->id,
    ];
    $this->postJson('/api/v1/routes', $payload)->assertCreated();
    $before = Route::query()->where('hostname', 'retry.test')->sole()->toArray();
    $this
        ->postJson('/api/v1/routes', [...$payload, 'publication' => 'public'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.retry_conflict');
    expect(Route::query()->where('hostname', 'retry.test')->sole()->toArray())->toBe($before);
});

it('refuses invalid or occupied active explicit hostnames before Route or projection state changes', function (): void {
    $this->target->update(['source_is_laravel' => false, 'provisioning_step' => 'active']);
    $route = app(CreateRouteAction::class)->execute(new \App\Data\Routes\CreateRouteData(
        appId: $this->orbitApp->id,
        hostname: 'active.example.test',
        publication: RoutePublication::Private,
        appInstanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);
    Route::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'hostname' => 'occupied.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    app()->instance(RouteHostnameProjector::class, Mockery::mock(RouteHostnameProjector::class));
    app()->instance(
        DevelopmentAppInstanceConfigurator::class,
        Mockery::mock(DevelopmentAppInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);
    $before = $route->fresh(['targets'])->toArray();

    $this->patchJson("/api/v1/routes/{$route->id}", ['hostname' => 'bad_name'])
        ->assertUnprocessable();
    $this
        ->patchJson("/api/v1/routes/{$route->id}", ['hostname' => 'occupied.example.test'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.hostname_conflict');

    expect($route->fresh(['targets'])->toArray())->toBe($before);
});

it('updates an active explicit private development hostname through convergence', function (): void {
    $this->target->update(['source_is_laravel' => false, 'provisioning_step' => 'active']);
    $route = app(CreateRouteAction::class)->execute(new \App\Data\Routes\CreateRouteData(
        appId: $this->orbitApp->id,
        hostname: 'active.example.test',
        publication: RoutePublication::Private,
        appInstanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);
    $projector = Mockery::mock(RouteHostnameProjector::class);
    $projector->shouldReceive('prepareWorkloadCertificate')->once();
    $projector->shouldReceive('prepareWorkloadCaddy')->once();
    $projector->shouldReceive('prepareRouterCertificate')->once();
    $projector->shouldReceive('prepareFirewallPolicy')->once();
    $projector->shouldReceive('verifyWorkload')->once();
    $projector->shouldReceive('prepareRouterCaddy')->once();
    $projector->shouldReceive('publishDns')->once();
    $projector->shouldReceive('cleanup')->once();
    app()->instance(RouteHostnameProjector::class, $projector);
    app()->instance(
        DevelopmentAppInstanceConfigurator::class,
        Mockery::mock(DevelopmentAppInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);

    $this
        ->patchJson("/api/v1/routes/{$route->id}", ['hostname' => 'next.example.test'])
        ->assertOk()
        ->assertJsonPath('data.hostname', 'next.example.test')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.failed_step', null)
        ->assertJsonPath('data.hostname_change_target', null);

    expect($this->target->refresh()->status)->toBe(AppInstanceState::Active);
});

it('keeps production hostname changes behind the active reconciliation refusal', function (): void {
    $this->target->update([
        'environment' => 'production',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
    ]);
    $route = app(CreateRouteAction::class)->execute(new \App\Data\Routes\CreateRouteData(
        appId: $this->orbitApp->id,
        hostname: 'production.example.test',
        publication: RoutePublication::Private,
        appInstanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);
    app()->instance(RouteHostnameProjector::class, Mockery::mock(RouteHostnameProjector::class));
    app()->instance(
        DevelopmentAppInstanceConfigurator::class,
        Mockery::mock(DevelopmentAppInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);
    $before = $route->fresh(['targets'])->toArray();

    $this
        ->patchJson("/api/v1/routes/{$route->id}", ['hostname' => 'next.example.test'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.reconciliation_required');

    expect($route->fresh(['targets'])->toArray())->toBe($before);
});

function route_node(string $name, string $wireguardIp, ?string $tld): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => $tld,
        'public_ssh_host' => '192.0.2.'.substr($wireguardIp, strrpos($wireguardIp, '.') + 1),
        'wireguard_ip' => $wireguardIp,
        'user' => 'orbit',
    ]);
}

function route_instance(OrbitApp $app, Node $node, string $name): AppInstance
{
    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/srv/orbit/apps/{$app->slug}/{$name}",
        'branch' => $name,
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::Active,
    ]);
}

/** @return list<array<string, mixed>> */
function route_api_routes(): array
{
    return Route::query()
        ->with('targets')
        ->orderBy('id')
        ->get()
        ->map(static fn (Route $route): array => $route->toArray())
        ->all();
}

/** @return list<array<string, mixed>> */
function route_api_target_rows(): array
{
    return RouteTarget::query()
        ->orderBy('id')
        ->get()
        ->map(static fn (RouteTarget $target): array => $target->getAttributes())
        ->all();
}

final readonly class RouteApiProjectionOwner implements DevelopmentProjectionOperationLock
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}

/** @return array{Cluster, Node} */
function route_cluster(string $name, ?string $tld): array
{
    $cluster = Cluster::query()->create(['name' => $name, 'tld' => $tld, 'state' => ClusterState::Active]);
    $router = route_node("{$name}-router", '10.44.0.20', null);
    $router->update(['cluster_id' => $cluster->id]);
    $router
        ->roles()
        ->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);

    return [$cluster, $router];
}
