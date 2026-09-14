<?php

declare(strict_types=1);

use App\Actions\Clusters\AttachClusterNodeAction;
use App\Actions\Clusters\UpdateClusterAction;
use App\Actions\Routes\CreateRouteAction;
use App\Data\Clusters\UpdateClusterData;
use App\Data\Routes\CreateRouteData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentResult;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRouteDomain;
use App\Domain\AppInstances\Environment\AppInstanceRouteEnvironmentSynchronizer;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\CertificateMode;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
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
use Illuminate\Support\Facades\DB;
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
        'domain' => ' App.Example.Test ',
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
        ->assertJsonPath('data.domain', 'app.example.test')
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
        ->toBe(Route::class)
        ->and(Activity::query()->where('request_id', $requestId)->firstOrFail()->command)
        ->toBe('route:create');

    $this->getJson('/api/v1/routes')->assertOk()->assertJsonPath('data.0.id', $routeId);
    $this->getJson("/api/v1/routes/{$routeId}")->assertOk()->assertJsonPath('data.id', $routeId);
    $this
        ->patchJson("/api/v1/routes/{$routeId}", [
            'publication' => 'public',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $routeId)
        ->assertJsonPath('data.domain', 'app.example.test')
        ->assertJsonPath('data.publication', 'public')
        ->assertJsonPath('data.status', 'pending');

    $this->target->update(['status' => AppInstanceState::Reserved]);
    $unsetRequestId = (string) Str::uuid();
    $this
        ->withHeader('X-Orbit-Request-Id', $unsetRequestId)
        ->deleteJson("/api/v1/routes/{$routeId}/target")
        ->assertOk()
        ->assertJsonPath('data.target', null);
    expect(Route::query()->sole()->node_id)
        ->toBe($this->node->id)
        ->and(Activity::query()->where('request_id', $unsetRequestId)->sole()->command)
        ->toBe('route:target:unset');

    $destroyRequestId = (string) Str::uuid();
    $this
        ->withHeader('X-Orbit-Request-Id', $destroyRequestId)
        ->deleteJson("/api/v1/routes/{$routeId}")
        ->assertOk();
    expect(Route::query()->count())
        ->toBe(0)
        ->and(RouteTarget::query()->count())
        ->toBe(0)
        ->and(Activity::query()->where('request_id', $destroyRequestId)->sole()->command)
        ->toBe('route:destroy');
});

it('creates targetless exclusive Node and active Cluster scopes', function (): void {
    $nodeRoute = $this->postJson('/api/v1/routes', [
        'app_id' => $this->orbitApp->id,
        'domain' => 'node.example.test',
        'publication' => 'private',
        'node_id' => $this->node->id,
    ])->assertCreated();
    expect($nodeRoute->json('data.node_id'))->toBe($this->node->id)->and($nodeRoute->json('data.target'))->toBeNull();

    [$cluster] = route_cluster('active', 'cluster.test');
    $this
        ->postJson('/api/v1/routes', [
            'app_id' => $this->orbitApp->id,
            'domain' => 'cluster.example.test',
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
        'domain' => 'fixed.example.test',
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

it('returns 409 with route.target_conflict when creating a Route for an AppInstance that already has one', function (): void {
    $existing = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $routesBefore = route_api_routes();
    $targetRowsBefore = route_api_target_rows();

    $this
        ->postJson('/api/v1/routes', [
            'app_id' => $this->orbitApp->id,
            'domain' => 'unused-host.example.test',
            'publication' => 'private',
            'app_instance_id' => $this->target->id,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_conflict')
        ->assertJsonPath(
            'error.message',
            "AppInstance [{$this->target->id}] is already associated with Route [{$existing->id}].",
        );

    expect(route_api_routes())
        ->toBe($routesBefore)
        ->and(route_api_target_rows())
        ->toBe($targetRowsBefore)
        ->and(Route::query()->where('domain', 'unused-host.example.test')->exists())
        ->toBeFalse();
});

it('keeps every Route association unchanged for exact target no-ops', function (): void {
    $targeted = app(CreateRouteAction::class)->ensureForAppInstance($this->target, null);
    $targeted->update(['status' => 'active']);
    $empty = $this->postJson('/api/v1/routes', [
        'app_id' => $this->orbitApp->id,
        'domain' => 'empty.example.test',
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
        'domain' => 'feature.acme.collision.test',
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
        'domain' => 'legacy.example.test',
        'certificate_mode' => CertificateMode::OrbitCa,
        'status' => LifecycleStatus::Active,
    ]);
    $workspace = Workspace::query()->create([
        'instance_id' => $legacy->id,
        'name' => 'preview',
        'branch' => 'preview',
        'checkout_path' => '/srv/orbit/legacy/acme/preview',
        'domain' => 'preview.example.test',
        'status' => LifecycleStatus::Active,
    ]);
    $legacyBefore = $legacy->only(['domain', 'certificate_mode']);
    $workspaceBefore = $workspace->only(['domain']);

    $route = $this->postJson('/api/v1/routes', [
        'app_id' => $this->orbitApp->id,
        'domain' => 'route.example.test',
        'publication' => 'private',
        'node_id' => $this->node->id,
    ])->assertCreated();
    $routeId = $route->json('data.id');
    $routeId = $this
        ->patchJson("/api/v1/routes/{$routeId}", ['domain' => 'changed.example.test'])
        ->assertOk()
        ->json('data.id');
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

    expect($legacy->refresh()->only(['domain', 'certificate_mode']))
        ->toBe($legacyBefore)
        ->and($workspace->refresh()->only(['domain']))
        ->toBe($workspaceBefore);
});

it('rejects malformed input, caller-owned fields, arrays, and conflicting retries unchanged', function (): void {
    foreach (['bad_name', '-bad.test', str_repeat('a', 254)] as $domain) {
        $this->postJson('/api/v1/routes', [
            'app_id' => $this->orbitApp->id,
            'domain' => $domain,
            'publication' => 'private',
            'node_id' => $this->node->id,
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/routes', [
        'app_id' => $this->orbitApp->id,
        'domain' => 'safe.test',
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
        'domain' => 'retry.test',
        'publication' => 'private',
        'node_id' => $this->node->id,
    ];
    $this->postJson('/api/v1/routes', $payload)->assertCreated();
    $before = Route::query()->where('domain', 'retry.test')->sole()->toArray();
    $this
        ->postJson('/api/v1/routes', [...$payload, 'publication' => 'public'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.retry_conflict');
    expect(Route::query()->where('domain', 'retry.test')->sole()->toArray())->toBe($before);
});

it('refuses invalid or occupied active explicit domains before Route or projection state changes', function (): void {
    $this->target->update(['source_is_laravel' => false, 'provisioning_step' => 'active']);
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $this->orbitApp->id,
        domain: 'active.example.test',
        publication: RoutePublication::Private,
        appInstanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);
    Route::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'domain' => 'occupied.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class));
    app()->instance(
        DevelopmentAppInstanceConfigurator::class,
        Mockery::mock(DevelopmentAppInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);
    $before = $route->fresh(['targets'])->toArray();

    $this->patchJson("/api/v1/routes/{$route->id}", ['domain' => 'bad_name'])
        ->assertUnprocessable();
    $this
        ->patchJson("/api/v1/routes/{$route->id}", ['domain' => 'occupied.example.test'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.domain_conflict');

    expect($route->fresh(['targets'])->toArray())->toBe($before);
});

it('returns 409 instance.source_profile_missing for an explicit domain change without a recorded profile', function (): void {
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $this->orbitApp->id,
        domain: 'active.example.test',
        publication: RoutePublication::Private,
        appInstanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);
    $this->target->update(['provisioning_step' => 'active']);
    app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class));
    app()->instance(
        DevelopmentAppInstanceConfigurator::class,
        Mockery::mock(DevelopmentAppInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);
    $before = $route->fresh(['targets'])->toArray();

    $this
        ->patchJson("/api/v1/routes/{$route->id}", ['domain' => 'next.example.test'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.source_profile_missing')
        ->assertJsonPath(
            'error.message',
            'The AppInstance has no recorded source profile. Repeat the same creation request with recover_source_profile to inspect the source and store the complete profile.',
        );

    expect($route->fresh(['targets'])->toArray())->toBe($before);
});

it('updates an active explicit private development domain through a replacement Route', function (): void {
    $this->target->update(['source_is_laravel' => false, 'provisioning_step' => 'active']);
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $this->orbitApp->id,
        domain: 'active.example.test',
        publication: RoutePublication::Private,
        appInstanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);
    $projector = Mockery::mock(RouteDomainProjector::class);
    $projector->shouldReceive('prepareWorkloadCertificate')->once();
    $projector->shouldReceive('prepareWorkloadCaddy')->once();
    $projector->shouldReceive('prepareRouterCertificate')->once();
    $projector->shouldReceive('prepareFirewallPolicy')->once();
    $projector->shouldReceive('verifyWorkload')->twice();
    $projector->shouldReceive('prepareRouterCaddy')->once();
    $projector->shouldReceive('publishDns')->once();
    $projector->shouldReceive('cleanup')->once();
    app()->instance(RouteDomainProjector::class, $projector);
    app()->instance(
        DevelopmentAppInstanceConfigurator::class,
        Mockery::mock(DevelopmentAppInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);

    $updated = $this
        ->patchJson("/api/v1/routes/{$route->id}", ['domain' => 'next.example.test'])
        ->assertOk()
        ->assertJsonPath('data.domain', 'next.example.test')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.failed_step', null)
        ->assertJsonPath('data.replaced_by_route_id', null);

    expect($updated->json('data.id'))
        ->not->toBe($route->id)
        ->and(Route::query()->whereKey($route->id)->exists())
        ->toBeFalse()
        ->and($this->target->refresh()->status)
        ->toBe(AppInstanceState::Active);
});

it('keeps database cutover failures bounded through the Route update API', function (): void {
    $route = route_api_active_development_route($this->orbitApp, $this->target);
    $before = $route->fresh()->only([
        'id',
        'domain',
        'status',
        'replaces_route_id',
        'replaced_by_route_id',
        'replacement_step',
        'failed_step',
        'error_code',
    ]);
    app()->instance(RouteDomainProjector::class, route_api_domain_projector(rollback: true));
    app()->instance(
        DevelopmentAppInstanceConfigurator::class,
        Mockery::mock(DevelopmentAppInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER route_api_domain_change_cutover_failure
        BEFORE UPDATE OF status ON routes
        WHEN NEW.status = 'activating'
        BEGIN
            SELECT RAISE(ABORT, 'Injected database cutover failure.');
        END
        SQL);

    $this
        ->patchJson("/api/v1/routes/{$route->id}", ['domain' => 'next.example.test'])
        ->assertInternalServerError()
        ->assertJsonPath('error.code', 'gateway.unhandled');

    expect($route->refresh()->only([
        'id',
        'domain',
        'status',
        'replaces_route_id',
        'replaced_by_route_id',
        'replacement_step',
        'failed_step',
        'error_code',
    ]))
        ->toBe($before)
        ->and($route->isAuthoritative())
        ->toBeTrue()
        ->and(Route::query()->where('domain', 'next.example.test')->exists())
        ->toBeFalse();
});

it('keeps final cleanup failures bounded through the Route update API', function (): void {
    $route = route_api_active_development_route($this->orbitApp, $this->target);
    app()->instance(RouteDomainProjector::class, route_api_domain_projector(cleanup: true));
    app()->instance(
        DevelopmentAppInstanceConfigurator::class,
        Mockery::mock(DevelopmentAppInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER route_api_domain_change_cleanup_failure
        BEFORE DELETE ON routes
        WHEN OLD.status = 'retiring'
        BEGIN
            SELECT RAISE(ABORT, 'Injected cleanup persistence failure.');
        END
        SQL);

    $this
        ->patchJson("/api/v1/routes/{$route->id}", ['domain' => 'next.example.test'])
        ->assertInternalServerError()
        ->assertJsonPath('error.code', 'gateway.unhandled');

    $replacement = Route::query()->where('domain', 'next.example.test')->sole();

    expect($replacement->status)
        ->toBe(RouteStatus::Activating)
        ->and($replacement->isAuthoritative())
        ->toBeTrue()
        ->and($replacement->replaces_route_id)
        ->toBe($route->id)
        ->and($replacement->failed_step)
        ->toBe('cleanup')
        ->and($replacement->error_code)
        ->toBe('route.domain_change_failed')
        ->and($route->refresh()->status)
        ->toBe(RouteStatus::Retiring)
        ->and($route->domain)
        ->toBe('active.example.test');
});

it('updates an active explicit private production domain through a replacement Route', function (): void {
    $this->target->update([
        'environment' => 'production',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
    ]);
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $this->orbitApp->id,
        domain: 'production.example.test',
        publication: RoutePublication::Private,
        appInstanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);
    app()->instance(RouteDomainProjector::class, route_api_domain_projector(cleanup: true));
    app()->instance(
        DevelopmentAppInstanceConfigurator::class,
        Mockery::mock(DevelopmentAppInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);
    $targetId = $this->target->id;
    $environment = Mockery::mock(AppInstanceRouteEnvironmentSynchronizer::class);
    $environment
        ->shouldReceive('synchronizeRouteDomain')
        ->twice()
        ->withArgs(static fn (
            AppInstance $instance,
            AppInstanceEnvironmentRouteDomain $domain,
        ): bool => $instance->id === $targetId
            && $domain === AppInstanceEnvironmentRouteDomain::Candidate)
        ->andReturn(new AppInstanceEnvironmentResult($targetId, 'sync', true, 1));
    app()->instance(AppInstanceRouteEnvironmentSynchronizer::class, $environment);

    $updated = $this
        ->patchJson("/api/v1/routes/{$route->id}", ['domain' => 'next.example.test'])
        ->assertOk()
        ->assertJsonPath('data.domain', 'next.example.test')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.replaced_by_route_id', null);

    $replacement = Route::query()->findOrFail($updated->json('data.id'));

    expect($replacement->id)
        ->not->toBe($route->id)
        ->and(Route::query()->whereKey($route->id)->exists())
        ->toBeFalse()
        ->and($replacement->targets)
        ->toHaveCount(1)
        ->and($replacement->targets->sole()->app_instance_id)
        ->toBe($targetId);
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

function route_api_active_development_route(OrbitApp $app, AppInstance $target): Route
{
    $target->update([
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
    ]);
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $app->id,
        domain: 'active.example.test',
        publication: RoutePublication::Private,
        appInstanceId: $target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);

    return $route->refresh();
}

function route_api_domain_projector(bool $rollback = false, bool $cleanup = false): RouteDomainProjector
{
    $projector = Mockery::mock(RouteDomainProjector::class);

    foreach ([
        'prepareWorkloadCertificate',
        'prepareWorkloadCaddy',
        'prepareRouterCertificate',
        'prepareFirewallPolicy',
        'prepareRouterCaddy',
        'publishDns',
    ] as $method) {
        $projector->shouldReceive($method)->once();
    }

    $projector->shouldReceive('verifyWorkload')->times($cleanup ? 2 : 1);

    if ($rollback) {
        foreach (['rollbackDns', 'rollbackCaddy', 'rollbackCertificates'] as $method) {
            $projector->shouldReceive($method)->once();
        }
    }

    if ($cleanup) {
        $projector->shouldReceive('cleanup')->once();
    }

    return $projector;
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
