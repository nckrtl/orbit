<?php

declare(strict_types=1);

use App\Actions\Routes\CreateRouteAction;
use App\Actions\Routes\PublishPublicRouteAction;
use App\Data\Routes\CreateRouteData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\Environment\InstanceEnvironmentResult;
use App\Domain\Instances\Environment\InstanceEnvironmentRouteDomain;
use App\Domain\Instances\Environment\InstanceRouteEnvironmentSynchronizer;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionCloneRouteProjector;
use App\Domain\Instances\ProductionRouteProjector;
use App\Domain\Metrics\MetricsFleetReconcileException;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Metrics\MetricsReconcileComponent;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Infrastructure\Routes\NativePublicRouteEdgeProjector;
use App\Models\Activity;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteAnalyticsTracking;
use App\Models\RouteTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeNodeCaddyBuilds;
use Tests\Support\FakePublicRouteEdgeProjector;
use Tests\Support\FakeRouteRemovalProjector;

function pendingNodeRouteFixture(int $projectId, int $nodeId, string $domain): Route
{
    return Route::query()->create([
        'project_id' => $projectId,
        'node_id' => $nodeId,
        'domain' => $domain,
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
}

beforeEach(function (): void {
    $this->gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->orbitApp = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->node = route_node('dev-one', '10.44.0.2', 'one.test');
    $this->target = route_instance($this->orbitApp, $this->node, 'main');
    $this->removal = new FakeRouteRemovalProjector;
    app()->instance(RouteRemovalProjector::class, $this->removal);
});

it('creates an active instance route from an Instance and retries it', function (): void {
    $projector = Mockery::mock(DevelopmentRouteProjector::class);
    $projector->shouldReceive('converge')->once();
    app()->instance(DevelopmentRouteProjector::class, $projector);
    $payload = ['instance_id' => $this->target->id, 'domain' => 'shop.example.test', 'publication' => 'private'];

    $created = $this->postJson('/api/v1/routes', $payload)
        ->assertCreated()
        ->assertJsonPath('data.project_id', $this->orbitApp->id)
        ->assertJsonPath('data.status', 'active');

    $route = Route::query()->with('targets')->findOrFail($created->json('data.id'));
    expect($route->node_id)->toBe($this->node->id)
        ->and($route->targets->sole()->instance_id)->toBe($this->target->id);

    $this->postJson('/api/v1/routes', $payload)->assertOk()->assertJsonPath('data.id', $route->id);
    $this->postJson('/api/v1/routes', [...$payload, 'publication' => 'public'])
        ->assertConflict()->assertJsonPath('error.code', 'route.retry_conflict');
});

it('creates a public instance route with the requested publication', function (): void {
    $projector = Mockery::mock(DevelopmentRouteProjector::class);
    $projector->shouldReceive('converge')->once();
    app()->instance(DevelopmentRouteProjector::class, $projector);
    app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);

    $this->postJson('/api/v1/routes', [
        'instance_id' => $this->target->id,
        'domain' => 'public.example.test',
        'publication' => 'public',
    ])->assertCreated()->assertJsonPath('data.publication', 'public')->assertJsonPath('data.status', 'active');
});

it('projects a production instance route before marking it active', function (): void {
    $this->node->roles()->where('role', RoleName::AppDev)->delete();
    orbit_test_set_app_placement_role($this->node, true);
    $projection = Mockery::mock(ProductionRouteProjector::class);
    $projection->shouldReceive('prepareCertificate', 'prepareRuntime', 'prepareFirewall')->once();
    app()->instance(ProductionRouteProjector::class, $projection);
    $steps = Mockery::mock(ProductionCloneRouteProjector::class);
    $steps->shouldReceive('prepareWorkloadCaddy', 'prepareRouterCertificate', 'prepareRouteFirewall', 'verifyWorkload', 'prepareRouterCaddy', 'prepareDns')->once();
    app()->instance(ProductionCloneRouteProjector::class, $steps);

    $this->postJson('/api/v1/routes', [
        'instance_id' => $this->target->id,
        'domain' => 'production.example.test',
    ])->assertCreated()->assertJsonPath('data.status', 'active');
});

it('does not adopt an older pending explicit app Route during create retry', function (): void {
    $pending = app(CreateRouteAction::class)->execute(new CreateRouteData(
        projectId: $this->orbitApp->id,
        domain: 'older.example.test',
        publication: RoutePublication::Private,
        instanceId: $this->target->id,
    ))['route'];

    $this->postJson('/api/v1/routes', [
        'instance_id' => $this->target->id,
        'domain' => 'older.example.test',
    ])->assertConflict()->assertJsonPath('error.code', 'route.activation_unsupported');
    expect($pending->refresh()->status)->toBe(RouteStatus::Pending);
});

it('resumes an instance route after workload projection fails', function (): void {
    $projection = Mockery::mock(DevelopmentRouteProjector::class);
    $projection->shouldReceive('converge')->twice()->andReturnUsing(static function () use (&$attempts): void {
        $attempts = ($attempts ?? 0) + 1;
        if ($attempts === 1) {
            throw new RuntimeConvergenceException('projection', 'route.test_projection_failed', 'Injected projection failure.');
        }
    });
    app()->instance(DevelopmentRouteProjector::class, $projection);
    $payload = ['instance_id' => $this->target->id, 'domain' => 'resume.example.test'];

    $this->postJson('/api/v1/routes', $payload)->assertStatus(502);
    $route = Route::query()->where('domain', $payload['domain'])->sole();
    expect($route->status)->toBe(RouteStatus::Activating);

    $this->postJson('/api/v1/routes', $payload)->assertOk()
        ->assertJsonPath('data.id', $route->id)
        ->assertJsonPath('data.status', 'active');
    $this->assertDatabaseCount('routes', 1);
    $this->assertDatabaseCount('route_targets', 1);
});

it('rebuilds a separate Ingress from a persisted uncertain public-handler checkpoint', function (): void {
    [, $router, $ingress, $workload, $target] = route_public_topology($this->orbitApp, name: 'crash-edge', createRoute: false);
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        projectId: $this->orbitApp->id,
        domain: 'crash-edge.example.test',
        publication: RoutePublication::Public,
        instanceId: $target->id,
    ))['route'];
    $route->update(['status' => RouteStatus::Activating, 'replacement_step' => RouteReplacementStep::PublicActivated]);
    $projection = Mockery::mock(ProductionRouteProjector::class);
    $projection->shouldReceive('prepareCertificate', 'prepareRuntime', 'prepareFirewall')->once();
    app()->instance(ProductionRouteProjector::class, $projection);
    $steps = Mockery::mock(ProductionCloneRouteProjector::class);
    $steps->shouldReceive('prepareWorkloadCaddy', 'prepareRouterCertificate', 'prepareRouteFirewall', 'verifyWorkload', 'prepareRouterCaddy', 'prepareDns')->once();
    app()->instance(ProductionCloneRouteProjector::class, $steps);
    $builds = app(NodeCaddyBuilds::class);
    assert($builds instanceof FakeNodeCaddyBuilds);
    expect($builds->built)->toBe([])
        ->and($ingress->id)->not->toBe($router->id)->not->toBe($workload->id);
    $builds->onBuild = static function (Node $node) use ($route, $ingress): void {
        if ($node->is($ingress)) {
            expect($route->refresh()->status)->toBe(RouteStatus::Activating)
                ->and($route->replacement_step)->toBe(RouteReplacementStep::PublicActivated);
        }
    };
    $firewall = Mockery::mock(NodeRoleFirewallManager::class);
    $firewall->shouldReceive('converge')->once()->withArgs(
        static fn (Node $node): bool => $node->is($ingress) && $builds->built === [$ingress->name, $router->name],
    );
    app()->instance(NodeRoleFirewallManager::class, $firewall);
    app()->instance(PublicRouteEdgeProjector::class, app(NativePublicRouteEdgeProjector::class));

    $this->postJson('/api/v1/routes', [
        'instance_id' => $target->id,
        'domain' => $route->domain,
        'publication' => 'public',
    ])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.id', $route->id);
    expect($builds->built)->toBe([$ingress->name, $router->name]);
});

it('resumes a public instance route after an ingress step fails', function (string $failedStep): void {
    [, , , , $target] = route_public_topology($this->orbitApp, name: 'retry-edge', createRoute: false);
    $projection = Mockery::mock(ProductionRouteProjector::class);
    $projection->shouldReceive('prepareCertificate', 'prepareRuntime', 'prepareFirewall')->twice();
    app()->instance(ProductionRouteProjector::class, $projection);
    $steps = Mockery::mock(ProductionCloneRouteProjector::class);
    $steps->shouldReceive('prepareWorkloadCaddy', 'prepareRouterCertificate', 'prepareRouteFirewall', 'verifyWorkload', 'prepareRouterCaddy', 'prepareDns')->twice();
    app()->instance(ProductionCloneRouteProjector::class, $steps);
    $edge = new FakePublicRouteEdgeProjector;
    $edge->failures[$failedStep] = 1;
    app()->instance(PublicRouteEdgeProjector::class, $edge);
    $payload = ['instance_id' => $target->id, 'domain' => 'retry-public.example.test', 'publication' => 'public'];

    $this->postJson('/api/v1/routes', $payload)->assertStatus(502);
    $route = Route::query()->where('domain', $payload['domain'])->sole();
    expect($route->status)->toBe(RouteStatus::Activating);
    if ($failedStep === 'public-activated') {
        expect($edge->calls)->toContain('rollback-public-edge');
    }

    $this->postJson('/api/v1/routes', $payload)->assertOk()
        ->assertJsonPath('data.id', $route->id)
        ->assertJsonPath('data.status', 'active');
    expect(collect($edge->calls)->filter(static fn (string $call): bool => $call === $failedStep)->count())->toBe(2)
        ->and($route->refresh()->replacement_step)->toBe(RouteReplacementStep::IngressFirewall);
})->with(['public-activated', 'ingress-firewall']);

it('defaults an instance route publication to private', function (): void {
    $projector = Mockery::mock(DevelopmentRouteProjector::class);
    $projector->shouldReceive('converge')->once();
    app()->instance(DevelopmentRouteProjector::class, $projector);

    $this->postJson('/api/v1/routes', [
        'instance_id' => $this->target->id,
        'domain' => 'default.example.test',
    ])->assertCreated()->assertJsonPath('data.publication', 'private')->assertJsonPath('data.status', 'active');
});

it('refuses a package Instance whose repository root is not a supported Route web root', function (): void {
    $this->orbitApp->update([
        'type' => ProjectType::NodePackage,
        'root' => '.',
    ]);

    $this->postJson('/api/v1/routes', [
        'domain' => 'node-package.example.test',
        'publication' => 'private',
        'instance_id' => $this->target->id,
    ])->assertConflict()
        ->assertJsonPath('error.code', 'route.target_web_root_unsupported');

    $this->assertDatabaseCount('routes', 0);
    $this->assertDatabaseCount('route_targets', 0);
});

it('refuses to attach a package Instance with repository root . to an existing Route', function (): void {
    $this->orbitApp->update([
        'type' => ProjectType::NodePackage,
        'root' => '.',
    ]);

    $route = pendingNodeRouteFixture($this->orbitApp->id, $this->node->id, 'targetless.example.test')->id;

    $this->putJson("/api/v1/routes/{$route}/target", [
        'instance_id' => $this->target->id,
    ])->assertConflict()
        ->assertJsonPath('error.code', 'route.target_web_root_unsupported');

    $this->assertDatabaseCount('route_targets', 0);
});

it('lists, shows, updates, clears, and removes a pre-existing explicit Route', function (): void {
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        projectId: $this->orbitApp->id,
        domain: 'app.example.test',
        publication: RoutePublication::Private,
        instanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $routeId = $route->id;
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

    $this->target->update(['status' => InstanceState::Reserved]);
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

it('preserves a structured Metrics runtime failure through the Route update API', function (): void {
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        projectId: $this->orbitApp->id,
        domain: 'metrics-error.example.test',
        publication: RoutePublication::Private,
        instanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('reconcile')->once()->andThrow(new MetricsFleetReconcileException(
        MetricsReconcileComponent::Runtime,
        $this->gateway->id,
        'metrics.docker_unavailable',
        'Metrics Docker is unavailable.',
        502,
        new ResourceOperationException('metrics.docker_unavailable', 'Metrics Docker is unavailable.', 502),
    ));
    app()->instance(MetricsFleetReconciler::class, $metrics);

    $this->patchJson("/api/v1/routes/{$route->id}", ['publication' => 'public'])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'metrics.docker_unavailable')
        ->assertJsonPath('error.message', 'Metrics Docker is unavailable.');
});

it('refuses targetless app Route creation in the action', function (): void {
    expect(fn () => app(CreateRouteAction::class)->execute(new CreateRouteData(
        projectId: $this->orbitApp->id,
        domain: 'unsupported.example.test',
        publication: RoutePublication::Private,
        nodeId: $this->node->id,
    )))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseCount('routes', 0);
});

it('rejects Project and targetless app Route creation inputs', function (): void {
    foreach ([
        ['project_id' => $this->orbitApp->id, 'instance_id' => $this->target->id],
        ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id],
        ['cluster_id' => route_cluster('active', 'cluster.test')[0]->id],
        ['instance_id' => $this->target->id, 'node_id' => $this->node->id],
    ] as $scope) {
        $this->postJson('/api/v1/routes', [
            ...$scope,
            'domain' => 'invalid.example.test',
            'publication' => 'private',
        ])->assertUnprocessable();
    }

    $this->assertDatabaseCount('routes', 0);
});

it('returns 409 before replacing or clearing an active target or removing its Route', function (): void {
    $route = app(CreateRouteAction::class)->ensureForInstance($this->target, null);
    $route->update(['status' => 'active']);
    $otherNode = route_node('dev-two', '10.44.0.3', 'two.test');
    $other = route_instance($this->orbitApp, $otherNode, 'feature');
    $before = $route->fresh(['targets'])->toArray();
    $targetRowsBefore = route_api_target_rows();
    $message = "Active Instance [{$this->target->id}] must remain associated with Route [{$route->id}].";

    $this
        ->putJson("/api/v1/routes/{$route->id}/target", [
            'instance_id' => $other->id,
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
        ->not->toBeNull()
        ->and($this->removal->events)
        ->toBe([]);
});

it('removes Route-owned projections for an untargeted Route and preserves unrelated Routes and workloads', function (): void {
    $routeModel = app(CreateRouteAction::class)->execute(new CreateRouteData(
        projectId: $this->orbitApp->id,
        domain: 'keep.example.test',
        publication: RoutePublication::Private,
        instanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $routeModel->update(['status' => RouteStatus::Active]);
    $this->target->update(['status' => InstanceState::Reserved]);
    $routeModel->targets()->delete();

    $unrelated = pendingNodeRouteFixture($this->orbitApp->id, $this->node->id, 'other.example.test');
    $workload = route_instance($this->orbitApp, $this->node, 'sibling');

    $this->deleteJson("/api/v1/routes/{$routeModel->id}")->assertOk();

    expect(Route::query()->whereKey($routeModel->id)->exists())
        ->toBeFalse()
        ->and($this->removal->events)
        ->toBe(['dns', 'caddy', 'certificates', 'firewall'])
        ->and($this->removal->routeIds)
        ->toBe([$routeModel->id, $routeModel->id, $routeModel->id, $routeModel->id])
        ->and(Route::query()->whereKey($unrelated->id)->exists())
        ->toBeTrue()
        ->and($workload->fresh())
        ->not->toBeNull()
        ->and($this->target->fresh())
        ->not->toBeNull()
        ->and($this->node->fresh())
        ->not->toBeNull();
});

it('refuses a tracking route', function (): void {
    $route = Route::query()->create([
        'kind' => RouteKind::AnalyticsTracking,
        'project_id' => null,
        'node_id' => $this->node->id,
        'cluster_id' => null,
        'generation_basis_node_id' => null,
        'domain' => 'tracking.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    RouteAnalyticsTracking::query()->create([
        'route_id' => $route->id,
        'instance_id' => $this->target->id,
    ]);

    $this->deleteJson("/api/v1/routes/{$route->id}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.tracking_managed');

    expect($route->fresh())->not->toBeNull()
        ->and($this->removal->events)->toBe([]);
});

it('accepts the Route id in a DELETE body and still binds the path', function (): void {
    $routeId = pendingNodeRouteFixture($this->orbitApp->id, $this->node->id, 'mcp-delete.example.test')->id;

    $this->deleteJson("/api/v1/routes/{$routeId}", ['route' => $routeId])->assertOk();

    expect(Route::query()->whereKey($routeId)->exists())->toBeFalse()
        ->and($this->removal->events)
        ->toBe(['dns', 'caddy', 'certificates', 'firewall']);
});

it('releases the hostname after untargeted removal', function (): void {
    $routeId = pendingNodeRouteFixture($this->orbitApp->id, $this->node->id, 'released.example.test')->id;

    $this->deleteJson("/api/v1/routes/{$routeId}")->assertOk();
    $this->deleteJson("/api/v1/routes/{$routeId}")->assertNotFound();

    expect(Route::query()->where('domain', 'released.example.test')->exists())->toBeFalse();
});

it('returns 409 with both Routes when the requested target belongs to another Route', function (): void {
    $existing = app(CreateRouteAction::class)->ensureForInstance($this->target, null);
    $requestedId = pendingNodeRouteFixture($this->orbitApp->id, $this->node->id, 'fixed.example.test')->id;
    $routesBefore = route_api_routes();
    $targetRowsBefore = route_api_target_rows();

    $this
        ->putJson("/api/v1/routes/{$requestedId}/target", [
            'instance_id' => $this->target->id,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_conflict')
        ->assertJsonPath(
            'error.message',
            "Instance [{$this->target->id}] is already associated with Route [{$existing->id}] and cannot be assigned to Route [{$requestedId}].",
        );

    expect(route_api_routes())
        ->toBe($routesBefore)
        ->and(route_api_target_rows())
        ->toBe($targetRowsBefore);
});

it('returns 409 with route.target_conflict when creating a Route for an Instance that already has one', function (): void {
    $existing = app(CreateRouteAction::class)->ensureForInstance($this->target, null);
    $routesBefore = route_api_routes();
    $targetRowsBefore = route_api_target_rows();

    $this
        ->postJson('/api/v1/routes', [
            'domain' => 'unused-host.example.test',
            'publication' => 'private',
            'instance_id' => $this->target->id,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_conflict')
        ->assertJsonPath(
            'error.message',
            "Instance [{$this->target->id}] is already associated with Route [{$existing->id}].",
        );

    expect(route_api_routes())
        ->toBe($routesBefore)
        ->and(route_api_target_rows())
        ->toBe($targetRowsBefore)
        ->and(Route::query()->where('domain', 'unused-host.example.test')->exists())
        ->toBeFalse();
});

it('keeps every Route association unchanged for exact target no-ops', function (): void {
    $targeted = app(CreateRouteAction::class)->ensureForInstance($this->target, null);
    $targeted->update(['status' => 'active']);
    $emptyId = pendingNodeRouteFixture($this->orbitApp->id, $this->node->id, 'empty.example.test')->id;
    $routesBefore = route_api_routes();
    $targetRowsBefore = route_api_target_rows();

    $this
        ->putJson("/api/v1/routes/{$targeted->id}/target", [
            'instance_id' => $this->target->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.target.instance_id', $this->target->id);
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
    $route = app(CreateRouteAction::class)->ensureForInstance($this->target, null);
    $before = $route->fresh(['targets'])->toArray();
    $otherApp = Project::query()->create([
        'name' => 'Other',
        'slug' => 'other',
        'repository_url' => 'https://example.test/other.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $foreign = route_instance($otherApp, $this->node, 'foreign');

    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'instance_id' => $foreign->id,
    ])->assertConflict()->assertJsonPath('error.code', 'route.target_app_conflict');

    expect($route->fresh(['targets'])->toArray())->toBe($before);

    $inactive = route_instance($this->orbitApp, $this->node, 'inactive');
    $inactive->update(['status' => InstanceState::Reserved]);
    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'instance_id' => $inactive->id,
    ])->assertConflict()->assertJsonPath('error.code', 'route.target_inactive');
    expect($route->fresh(['targets'])->toArray())->toBe($before);

    $tldlessNode = route_node('tldless', '10.44.0.4', null);
    $tldless = route_instance($this->orbitApp, $tldlessNode, 'tldless');
    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'instance_id' => $tldless->id,
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
        'instance_id' => $clustered->id,
    ])->assertConflict()->assertJsonPath('error.code', 'route.router_required');
    expect($route->fresh(['targets'])->toArray())->toBe($before);

    $collisionNode = route_node('collision', '10.44.0.6', 'collision.test');
    $collision = route_instance($this->orbitApp, $collisionNode, 'feature');
    Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $collisionNode->id,
        'domain' => 'feature.acme.collision.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'instance_id' => $collision->id,
    ])->assertConflict();
    expect($route->fresh(['targets'])->toArray())->toBe($before);
});

it('rejects malformed input, caller-owned fields, arrays, and conflicting retries unchanged', function (): void {
    foreach (['bad_name', '-bad.test', str_repeat('a', 254)] as $domain) {
        $this->postJson('/api/v1/routes', [
            'project_id' => $this->orbitApp->id,
            'domain' => $domain,
            'publication' => 'private',
            'node_id' => $this->node->id,
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/routes', [
        'project_id' => $this->orbitApp->id,
        'domain' => 'safe.test',
        'publication' => 'private',
        'node_id' => $this->node->id,
        'targets' => [$this->target->id],
        'status' => 'active',
    ])->assertUnprocessable();

    $generated = app(CreateRouteAction::class)->ensureForInstance($this->target, null);
    $this
        ->deleteJson("/api/v1/routes/{$generated->id}/target", ['unsupported' => true])
        ->assertUnprocessable();
    expect($generated->targets()->count())->toBe(1);
    $this
        ->deleteJson("/api/v1/routes/{$generated->id}", ['unsupported' => true])
        ->assertUnprocessable();
    expect($generated->fresh())->not->toBeNull();

});

it('refuses invalid or occupied active explicit domains before Route or projection state changes', function (): void {
    $this->target->update(['source_is_laravel' => false, 'provisioning_step' => 'active']);
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        projectId: $this->orbitApp->id,
        domain: 'active.example.test',
        publication: RoutePublication::Private,
        instanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);
    Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'domain' => 'occupied.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class));
    app()->instance(
        DevelopmentInstanceConfigurator::class,
        Mockery::mock(DevelopmentInstanceConfigurator::class),
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
        projectId: $this->orbitApp->id,
        domain: 'active.example.test',
        publication: RoutePublication::Private,
        instanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);
    $this->target->update(['provisioning_step' => 'active']);
    app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class));
    app()->instance(
        DevelopmentInstanceConfigurator::class,
        Mockery::mock(DevelopmentInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);
    $before = $route->fresh(['targets'])->toArray();

    $this
        ->patchJson("/api/v1/routes/{$route->id}", ['domain' => 'next.example.test'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.source_profile_missing')
        ->assertJsonPath(
            'error.message',
            'The Instance has no recorded source profile and cannot be used.',
        );

    expect($route->fresh(['targets'])->toArray())->toBe($before);
});

it('updates an active explicit private development domain through a replacement Route', function (): void {
    $this->target->update(['source_is_laravel' => false, 'provisioning_step' => 'active']);
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        projectId: $this->orbitApp->id,
        domain: 'active.example.test',
        publication: RoutePublication::Private,
        instanceId: $this->target->id,
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
    $projector->shouldReceive('prepareCleanup')->once();
    $projector->shouldReceive('cleanup')->once();
    app()->instance(RouteDomainProjector::class, $projector);
    app()->instance(
        DevelopmentInstanceConfigurator::class,
        Mockery::mock(DevelopmentInstanceConfigurator::class),
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
        ->toBe(InstanceState::Active);
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
        DevelopmentInstanceConfigurator::class,
        Mockery::mock(DevelopmentInstanceConfigurator::class),
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
        DevelopmentInstanceConfigurator::class,
        Mockery::mock(DevelopmentInstanceConfigurator::class),
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
    $this->target->node->roles()->where('role', RoleName::AppDev)->delete();
    $this->target->node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $this->target->update([
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
    ]);
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        projectId: $this->orbitApp->id,
        domain: 'production.example.test',
        publication: RoutePublication::Private,
        instanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);
    app()->instance(RouteDomainProjector::class, route_api_domain_projector(cleanup: true));
    app()->instance(
        DevelopmentInstanceConfigurator::class,
        Mockery::mock(DevelopmentInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);
    $targetId = $this->target->id;
    $environment = Mockery::mock(InstanceRouteEnvironmentSynchronizer::class);
    $environment
        ->shouldReceive('synchronizeRouteDomain')
        ->twice()
        ->withArgs(static fn (
            Instance $instance,
            InstanceEnvironmentRouteDomain $domain,
        ): bool => $instance->id === $targetId
            && $domain === InstanceEnvironmentRouteDomain::Candidate)
        ->andReturn(new InstanceEnvironmentResult($targetId, 'sync', true, 1));
    app()->instance(InstanceRouteEnvironmentSynchronizer::class, $environment);

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
        ->and($replacement->targets->sole()->instance_id)
        ->toBe($targetId);
});

it('keeps publication=public without artifacts for a Node-scoped Route, inactive Cluster, or missing Ingress', function (): void {
    $edge = new FakePublicRouteEdgeProjector;
    app()->instance(PublicRouteEdgeProjector::class, $edge);
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);

    $active = app(CreateRouteAction::class)->execute(new CreateRouteData(
        projectId: $this->orbitApp->id,
        domain: 'node-public.example.test',
        publication: RoutePublication::Public,
        instanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $active->update(['status' => RouteStatus::Active]);

    expect($active->publication)
        ->toBe(RoutePublication::Public)
        ->and($edge->calls)
        ->toBe([]);

    $published = app(PublishPublicRouteAction::class)->execute($active, RoutePublication::Public);

    expect($published->id)
        ->toBe($active->id)
        ->and($published->publication)
        ->toBe(RoutePublication::Public)
        ->and($edge->calls)
        ->toBe([]);

    [$cluster, , , , , $clusterRoute] = route_public_topology(
        $this->orbitApp,
        domain: 'inactive-cluster.example.test',
        name: 'inactive-public',
    );
    $cluster->update(['state' => ClusterState::Inactive]);
    $inactiveCluster = app(PublishPublicRouteAction::class)->execute($clusterRoute, RoutePublication::Public);

    expect($inactiveCluster->publication)
        ->toBe(RoutePublication::Public)
        ->and($edge->calls)
        ->toBe([]);

    [$missingIngress] = route_cluster('missing-ingress', 'missing.test');
    $missing = Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'cluster_id' => $missingIngress->id,
        'domain' => 'missing-ingress.example.test',
        'provenance' => 'explicit',
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);

    expect($missing->publication)->toBe(RoutePublication::Public)->and($edge->calls)->toBe([]);
});

it('creates and shows a public Route without a Node public-IP field', function (): void {
    $created = Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'cluster_id' => route_cluster('shown-public', 'shown.test')[0]->id,
        'domain' => 'shown-public.example.test',
        'provenance' => 'explicit',
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);

    $shown = $this->getJson('/api/v1/routes/'.$created->id)->assertOk();

    expect($shown->json('data'))
        ->not->toHaveKey('public_ip')
        ->and($shown->json('data.publication'))
        ->toBe('public');
});

it('rolls a failed public activation back to the verified edge so the Ingress Node builds as before', function (bool $rollbackFails): void {
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('reconcile')->zeroOrMoreTimes();
    app()->instance(MetricsFleetReconciler::class, $metrics);
    [, , , , , $route] = route_public_topology($this->orbitApp);
    $edge = new FakePublicRouteEdgeProjector;
    $edge->failures = ['public-activated' => 1, 'rollback-public-edge' => $rollbackFails ? 1 : 0];
    app()->instance(PublicRouteEdgeProjector::class, $edge);
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);
    $renders = [];
    $edge->onRollback = function (Route $route) use (&$renders): void {
        $renders[] = new PublicRouteEligibility()->publicEdgeIsLive($route->refresh());
    };

    $this->patchJson("/api/v1/routes/{$route->id}", ['publication' => 'public'])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'route.test_public-activated');

    $route->refresh();

    expect($edge->calls)->toBe(['ingress-certificate', 'public-edge-verified', 'public-activated', 'rollback-public-edge'])
        ->and($route->replacement_step)->toBe(RouteReplacementStep::PublicEdgeVerified)
        ->and($renders)->toBe([false])
        ->and(new PublicRouteEligibility()->publicEdgeIsLive($route))->toBeFalse();
})->with(['rollback builds' => [false], 'rollback also fails' => [true]]);

it('publishes an eligible public Route on the same ID and names only the Ingress domain and Router upstream', function (): void {
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('reconcile')->twice();
    app()->instance(MetricsFleetReconciler::class, $metrics);
    [$cluster, $router, $ingress, $workload, $instance, $route] = route_public_topology($this->orbitApp);
    $edge = new FakePublicRouteEdgeProjector;
    app()->instance(PublicRouteEdgeProjector::class, $edge);
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);

    $updated = $this
        ->patchJson("/api/v1/routes/{$route->id}", ['publication' => 'public'])
        ->assertOk()
        ->assertJsonPath('data.id', $route->id)
        ->assertJsonPath('data.publication', 'public');

    expect($updated->json('data'))
        ->not->toHaveKey('public_ip')
        ->and($edge->calls)
        ->toBe(['ingress-certificate', 'public-edge-verified', 'public-activated', 'ingress-firewall']);

    $artifact = new IngressSiteRepository()->forRoute($route->refresh());
    expect($artifact->artifact())
        ->toBe(['domain' => $route->domain, 'router_upstream' => $router->lan_ip])
        ->and($artifact->artifact())
        ->not->toHaveKey('instance_id')
        ->not->toHaveKey('node_id')
        ->and(array_keys($artifact->artifact()))
        ->toBe(['domain', 'router_upstream']);

    $catalog = new NodeFirewallRuleCatalog;
    expect(collect($catalog->forRole($ingress, RoleName::Ingress))->map(fn ($rule) => $rule->shape->comment)->all())
        ->toBe(['orbit:ingress-http', 'orbit:ingress-https'])
        ->and($catalog->forRole($router, RoleName::Ingress))
        ->toBeEmpty()
        ->and($catalog->forRole($workload, RoleName::AppProd))
        ->toBeEmpty();

    $override = $edge->privateOverride($route);
    expect($override->domain)
        ->toBe($route->domain)
        ->and($override->routerAddress)
        ->toBe($router->lan_ip)
        ->and($override->workloadAddress)
        ->toBe($workload->lan_ip);

    expect($instance->refresh()->status->value)->toBe('active');
});

it('reserves a replacement Route for a combined domain and publication change', function (): void {
    [$cluster, $router, $ingress, $workload, $instance, $route] = route_public_topology(
        $this->orbitApp,
        publication: RoutePublication::Private,
        domain: 'preview.example.test',
    );
    $edge = new FakePublicRouteEdgeProjector;
    app()->instance(PublicRouteEdgeProjector::class, $edge);
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);
    app()->instance(RouteDomainProjector::class, route_api_domain_projector(cleanup: true));
    app()->instance(
        DevelopmentInstanceConfigurator::class,
        Mockery::mock(DevelopmentInstanceConfigurator::class),
    );
    $environment = Mockery::mock(InstanceRouteEnvironmentSynchronizer::class);
    $environment->shouldReceive('synchronizeRouteDomain')->twice()->andReturn(
        new InstanceEnvironmentResult($instance->id, 'sync', true, 1),
    );
    app()->instance(InstanceRouteEnvironmentSynchronizer::class, $environment);

    $updated = $this
        ->patchJson("/api/v1/routes/{$route->id}", [
            'domain' => 'final.example.test',
            'publication' => 'public',
        ])
        ->assertOk()
        ->assertJsonPath('data.domain', 'final.example.test')
        ->assertJsonPath('data.publication', 'public');

    expect($updated->json('data.id'))
        ->not->toBe($route->id)
        ->and(Route::query()->whereKey($route->id)->exists())
        ->toBeFalse();
});

it('composes one public Caddy site when Ingress shares a Node and uses LAN without WireGuard fallback', function (): void {
    [$cluster, $router, $ingress, $workload, $instance, $route] = route_public_topology($this->orbitApp);
    $route->update(['replacement_step' => RouteReplacementStep::IngressFirewall]);
    $route->refresh();
    $sites = new DevelopmentSiteRepository;
    $renderer = new DevelopmentCaddyConfigRenderer;

    $separate = $renderer->render($sites->forNode($ingress, $route));
    expect($separate)
        ->toContain("{$route->domain} {")
        ->toContain("reverse_proxy https://{$router->lan_ip}")
        ->toContain('header_up X-Forwarded-Proto https')
        ->toContain('header_up X-Forwarded-Host '.$route->domain)
        ->toContain('tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->not->toContain("tls /etc/caddy/orbit-certificates/route-{$route->id}-ingress/current/cert.pem")
        ->not->toContain('https://'.$route->domain.' {');

    $colocated = route_node('ingress-router', '10.44.0.41', null);
    $colocated->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.40']);
    $router->roles()->where('role', RoleName::Router)->update(['node_id' => $colocated->id, 'cluster_id' => $cluster->id]);
    $ingress->roles()->where('role', RoleName::Ingress)->update(['node_id' => $colocated->id, 'cluster_id' => $cluster->id]);
    $route->refresh()->load(['cluster.routerAssignment.node', 'cluster.ingressAssignment.node', 'targets.instance.node']);
    $composed = $renderer->render($sites->forNode($colocated, $route));
    expect($composed)
        ->toContain("{$route->domain} {")
        ->toContain("reverse_proxy https://{$workload->lan_ip}")
        ->not->toContain('reverse_proxy https://127.0.0.1');

    $colocatedUpstream = new IngressSiteRepository()->forRoute($route->refresh());
    expect($colocatedUpstream->routerUpstream)->toBe('127.0.0.1');

    $colocated->roles()->where('role', RoleName::Ingress)->update(['node_id' => $ingress->id, 'cluster_id' => $cluster->id]);
    $colocated->roles()->where('role', RoleName::Router)->update(['node_id' => $router->id, 'cluster_id' => $cluster->id]);
    $router->update(['lan_ip' => null]);
    expect(new IngressSiteRepository()->forRoute($route->refresh())->routerUpstream)
        ->toBe($router->wireguard_ip);
});

it('sets a two-target production pool through the typed target-set contract', function (): void {
    [$route, $first, $second] = route_api_production_pool();
    app()->instance(RouteDomainProjector::class, route_api_target_set_projector());
    app()->instance(InstanceRouteEnvironmentSynchronizer::class, route_api_environment_fake());

    $this
        ->putJson("/api/v1/routes/{$route->id}/target", [
            'targets' => [$first->id, $second->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $route->id)
        ->assertJsonPath('data.domain', $route->domain)
        ->assertJsonPath('data.targets.0.instance_id', $first->id)
        ->assertJsonPath('data.targets.1.instance_id', $second->id)
        ->assertJsonMissingPath('data.targets.0.lan_ip')
        ->assertJsonMissingPath('data.lan_ip')
        ->assertJsonMissingPath('data.wireguard_ip');

    expect($route->refresh()->targets()->orderBy('position')->pluck('instance_id')->all())
        ->toBe([$first->id, $second->id]);
});

it('refuses a generated Route, duplicate target, foreign App or Cluster, inactive target, missing disposition, and concurrent intent', function (): void {
    [$route, $first, $second] = route_api_production_pool();
    $devNode = route_node('dev-pool-node', '10.44.0.93', 'devpool.test');
    $dev = route_instance($this->orbitApp, $devNode, 'dev-pool');
    $generated = app(CreateRouteAction::class)->ensureForInstance($this->target, null);
    $foreignApp = Project::query()->create([
        'name' => 'Other',
        'slug' => 'other',
        'repository_url' => 'https://example.test/other.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $foreign = route_instance($foreignApp, $second->node, 'foreign');
    [$foreignCluster] = route_cluster('foreign-pool', null);
    $foreignClusterNode = route_node('foreign-app-prod', '10.44.0.91', null);
    $foreignClusterNode->update(['cluster_id' => $foreignCluster->id, 'lan_ip' => '10.10.0.91']);
    $foreignClusterNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $foreignClusterInstance = route_instance($this->orbitApp, $foreignClusterNode, 'foreign-cluster');
    $foreignClusterInstance->update([
        'environment' => 'production',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'production_home' => '/var/www/acme/foreign-cluster',
        'production_user' => 'orbit-acme',
        'selected_php_version' => '8.5',
    ]);
    $inactiveNode = route_node('inactive-pool', '10.44.0.94', null);
    $inactiveNode->update(['cluster_id' => $route->cluster_id]);
    $inactive = Instance::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $inactiveNode->id,
        'name' => 'inactive',
        'environment' => 'production',
        'checkout_path' => '/var/www/inactive',
        'branch' => 'main',
        'starting_commit' => str_repeat('b', 40),
        'status' => InstanceState::SourceResolved,
    ]);
    $foreignRoute = Route::query()->create([
        'project_id' => $foreignApp->id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'foreign-dest.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $before = route_api_target_rows();

    $this->putJson("/api/v1/routes/{$generated->id}/target", ['targets' => [$this->target->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.pool_unsupported');
    $this->putJson("/api/v1/routes/{$route->id}/target", ['targets' => [$first->id, $first->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_conflict');
    $this->putJson("/api/v1/routes/{$route->id}/target", ['targets' => [$first->id, $dev->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.pool_unsupported');
    $this->putJson("/api/v1/routes/{$route->id}/target", ['targets' => [$first->id, $foreign->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_app_conflict');
    $this->putJson("/api/v1/routes/{$route->id}/target", ['targets' => [$first->id, $foreignClusterInstance->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_scope_conflict');
    $this->putJson("/api/v1/routes/{$route->id}/target", ['targets' => [$first->id, $inactive->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_inactive');
    $this->putJson("/api/v1/routes/{$route->id}/target", ['targets' => [$second->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_disposition_required');
    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'targets' => [$second->id],
        'dispositions' => [
            ['instance_id' => $first->id, 'route_id' => $foreignRoute->id],
        ],
    ])->assertConflict()->assertJsonPath('error.code', 'route.target_app_conflict');

    $route->update([
        'target_set_intent' => ['targets' => [$second->id], 'dispositions' => []],
        'target_set_step' => 'reserved',
    ]);
    $this->putJson("/api/v1/routes/{$route->id}/target", ['targets' => [$first->id, $second->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_set_conflict');

    expect(route_api_target_rows())->toBe($before);
});

it('projects a production pool with LAN preference, WireGuard fallback, mixed local composition, and empty-pool 503', function (): void {
    [$route, $first, $second] = route_api_production_pool();
    $route->targets()->create(['instance_id' => $second->id, 'position' => 1]);
    $route->load(['cluster.routerAssignment.node', 'targets.instance.node']);
    $router = $route->cluster?->routerAssignment?->node;
    expect($router)->not->toBeNull();
    $renderer = new DevelopmentCaddyConfigRenderer;

    $remote = $renderer->render(new DevelopmentSiteRepository()->forNode($router));
    expect($remote)
        ->toContain("reverse_proxy https://{$first->node->lan_ip} https://{$second->node->lan_ip}")
        ->toContain('lb_policy round_robin')
        ->toContain('fail_duration 10s')
        ->not->toContain('https://'.$first->node->wireguard_ip);

    $second->node->update(['lan_ip' => null]);
    $route->refresh()->load(['cluster.routerAssignment.node', 'targets.instance.node']);
    $wireguard = $renderer->render(new DevelopmentSiteRepository()->forNode($router));
    expect($wireguard)
        ->toContain("https://{$first->node->lan_ip}")
        ->toContain("https://{$second->node->refresh()->wireguard_ip}");

    $first->node->update(['id' => $first->node->id]);
    $router->roles()->where('role', RoleName::AppProd)->delete();
    $router->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $first->update(['node_id' => $router->id]);
    $route->refresh()->load(['cluster.routerAssignment.node', 'targets.instance.project', 'targets.instance.node']);
    $composed = $renderer->render(new DevelopmentSiteRepository()->forNode($router));
    expect($composed)
        ->toContain('unix//run/orbit/route-'.$route->id.'-local.sock')
        ->toContain('https://'.$second->node->refresh()->wireguard_ip)
        ->not->toContain('reverse_proxy https://127.0.0.1')
        ->and(mb_substr_count($composed, "{$route->domain} {"))
        ->toBe(1);

    $first->update(['status' => InstanceState::SourceResolved]);
    $second->update(['status' => InstanceState::SourceResolved]);
    $route->targets()->delete();
    $route->refresh()->load(['cluster.routerAssignment.node', 'targets.instance.node']);
    $empty = $renderer->render(new DevelopmentSiteRepository()->forNode($router));
    expect($empty)
        ->toContain('Orbit Route unavailable')
        ->toContain('respond "Orbit Route unavailable\n" 503')
        ->not->toContain('10.10.0.61')
        ->not->toContain('10.44.0.62');
});

it('rejects caller-supplied backend URLs, addresses, Caddy directives, and policy fields on target-set', function (): void {
    [$route] = route_api_production_pool();

    $this
        ->putJson("/api/v1/routes/{$route->id}/target", [
            'targets' => [1],
            'backend_url' => 'http://10.10.0.8',
            'lan_ip' => '10.10.0.8',
            'caddy' => 'reverse_proxy 10.10.0.8',
            'lb_policy' => 'least_conn',
        ])
        ->assertUnprocessable();
});

it('reassigns a detached target and keeps every active App instance on exactly one Route', function (): void {
    [$route, $first, $second] = route_api_production_pool();
    $other = Route::query()->create([
        'project_id' => $route->project_id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'other.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    app()->instance(RouteDomainProjector::class, route_api_target_set_projector());
    app()->instance(InstanceRouteEnvironmentSynchronizer::class, route_api_environment_fake());

    $this
        ->putJson("/api/v1/routes/{$route->id}/target", [
            'targets' => [$second->id],
            'dispositions' => [
                ['instance_id' => $first->id, 'route_id' => $other->id],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.targets.0.instance_id', $second->id);

    expect($route->refresh()->targets()->pluck('instance_id')->all())
        ->toBe([$second->id])
        ->and($other->refresh()->targets()->pluck('instance_id')->all())
        ->toBe([$first->id]);
});

it('transfers a Project instance from another Route and leaves a vacated Route serving 503', function (): void {
    [$route, $first, $second] = route_api_production_pool();
    $other = Route::query()->create([
        'project_id' => $route->project_id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'vacated.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $other->targets()->create(['instance_id' => $second->id, 'position' => 0]);
    $other->update(['status' => 'active']);
    app()->instance(RouteDomainProjector::class, route_api_target_set_projector());
    app()->instance(InstanceRouteEnvironmentSynchronizer::class, route_api_environment_fake());

    $this
        ->putJson("/api/v1/routes/{$route->id}/target", [
            'targets' => [$first->id, $second->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.domain', $route->domain)
        ->assertJsonPath('data.targets.0.instance_id', $first->id)
        ->assertJsonPath('data.targets.1.instance_id', $second->id);

    expect($route->refresh()->targets()->orderBy('position')->pluck('instance_id')->all())
        ->toBe([$first->id, $second->id])
        ->and($other->refresh()->targets()->count())
        ->toBe(0)
        ->and($other->domain)
        ->toBe('vacated.example.test');

    $other->load(['cluster.routerAssignment.node', 'targets.instance.node']);
    $router = $other->cluster?->routerAssignment?->node;
    expect($router)->not->toBeNull();
    $sites = new DevelopmentSiteRepository()->forNode($router);
    $rendered = new DevelopmentCaddyConfigRenderer()->render($sites);
    $vacatedSite = collect(preg_split('/\n\n/', $rendered) ?: [])
        ->first(static fn (string $block): bool => str_contains($block, 'vacated.example.test'));
    expect($vacatedSite)
        ->toBeString()
        ->toContain('Orbit Route unavailable')
        ->toContain('respond "Orbit Route unavailable\n" 503')
        ->not->toContain('10.10.0.62');
});

it('leaves stored session environment keys unchanged during a pool change', function (): void {
    [$route, $first, $second] = route_api_production_pool();
    foreach ([$first, $second] as $instance) {
        $instance->environmentValues()->create(['env_key' => 'SESSION_DRIVER', 'env_value' => 'redis']);
        $instance->environmentValues()->create(['env_key' => 'SESSION_CONNECTION', 'env_value' => 'sessions']);
        $instance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://{{instance.domain}}']);
    }
    app()->instance(RouteDomainProjector::class, route_api_target_set_projector());
    app()->instance(InstanceRouteEnvironmentSynchronizer::class, route_api_environment_fake());

    $this
        ->putJson("/api/v1/routes/{$route->id}/target", [
            'targets' => [$first->id, $second->id],
        ])
        ->assertOk();

    foreach ([$first->refresh(), $second->refresh()] as $instance) {
        expect($instance->environmentValues()->where('env_key', 'SESSION_DRIVER')->value('env_value'))
            ->toBe('redis')
            ->and($instance->environmentValues()->where('env_key', 'SESSION_CONNECTION')->value('env_value'))
            ->toBe('sessions')
            ->and($instance->environmentValues()->where('env_key', 'APP_URL')->value('env_value'))
            ->toBe('https://{{instance.domain}}');
    }
});

it('returns the unchanged Route for an identical completed target-set change', function (): void {
    [$route, $first, $second] = route_api_production_pool();
    $route->targets()->create(['instance_id' => $second->id, 'position' => 1]);
    $before = route_api_target_rows();

    $this
        ->putJson("/api/v1/routes/{$route->id}/target", [
            'targets' => [$first->id, $second->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.targets.0.instance_id', $first->id)
        ->assertJsonPath('data.targets.1.instance_id', $second->id);

    expect(route_api_target_rows())->toBe($before);
});

/** @return array{Route, Instance, Instance} */
function route_api_production_pool(): array
{
    [$cluster, $router] = route_cluster('pool', null);
    $firstNode = route_node('pool-one', '10.44.0.61', null);
    $firstNode->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.61']);
    $firstNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $secondNode = route_node('pool-two', '10.44.0.62', null);
    $secondNode->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.62']);
    $secondNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $first = route_instance(test()->orbitApp, $firstNode, 'one');
    $second = route_instance(test()->orbitApp, $secondNode, 'two');
    foreach ([$first, $second] as $instance) {
        $instance->update([
            'environment' => 'production',
            'source_is_laravel' => false,
            'provisioning_step' => 'active',
            'production_home' => '/var/www/acme/'.$instance->name,
            'production_user' => 'orbit-acme',
            'selected_php_version' => '8.5',
        ]);
    }
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        projectId: test()->orbitApp->id,
        domain: 'pool.example.test',
        publication: RoutePublication::Private,
        instanceId: $first->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => RouteStatus::Active]);

    return [$route->refresh(), $first->refresh(), $second->refresh()];
}

function route_api_target_set_projector(): RouteDomainProjector
{
    $projector = Mockery::mock(RouteDomainProjector::class);
    $projector->shouldIgnoreMissing();

    return $projector;
}

function route_api_environment_fake(): InstanceRouteEnvironmentSynchronizer
{
    return new class implements InstanceRouteEnvironmentSynchronizer
    {
        public function synchronizeRouteDomain(
            Instance $instance,
            InstanceEnvironmentRouteDomain $domain,
            ?string $app = null,
        ): InstanceEnvironmentResult {
            return new InstanceEnvironmentResult($instance->id, 'sync', false, 0);
        }
    };
}

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

function route_instance(Project $project, Node $node, string $name): Instance
{
    $production = $node->roles()->where('role', RoleName::AppProd)->where('status', LifecycleStatus::Active)->exists();
    orbit_test_set_app_placement_role($node, $production);

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/srv/orbit/apps/{$project->slug}/{$name}",
        'branch' => $name,
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::Active,
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

function route_api_active_development_route(Project $project, Instance $target): Route
{
    $target->update([
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
    ]);
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        projectId: $project->id,
        domain: 'active.example.test',
        publication: RoutePublication::Private,
        instanceId: $target->id,
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
        'prepareIngressCertificate',
        'prepareIngressFirewall',
        'verifyPublicEdge',
        'activatePublicHandler',
        'rollbackPublicEdge',
        'publishDns',
    ] as $method) {
        $projector->shouldReceive($method)->zeroOrMoreTimes();
    }

    $projector->shouldReceive('verifyWorkload')->times($cleanup ? 2 : 1);

    if ($rollback) {
        foreach (['rollbackDns', 'rollbackCaddy', 'rollbackCertificates'] as $method) {
            $projector->shouldReceive($method)->once();
        }
    }

    if ($cleanup) {
        $projector->shouldReceive('prepareCleanup')->once();
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

/** @return array{Cluster, Node, Node, Node, Instance, Route} */
function route_public_topology(
    Project $project,
    RoutePublication $publication = RoutePublication::Public,
    string $domain = 'public.example.test',
    string $name = 'public',
    bool $createRoute = true,
): array {
    [$cluster, $router] = route_cluster($name, "{$name}.test");
    $router->update(['lan_ip' => '10.10.0.20']);
    $ingress = route_node("{$name}-ingress", '10.44.0.30', null);
    $ingress->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.30']);
    $ingress->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Ingress,
        'status' => LifecycleStatus::Active,
    ]);
    $workload = route_node("{$name}-prod", '10.44.0.31', null);
    $workload->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.10']);
    $workload->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $instance = route_instance($project, $workload, 'production');
    $instance->update([
        'environment' => 'production',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'production_home' => '/var/www/acme',
        'production_user' => 'orbit-acme',
        'selected_php_version' => '8.5',
    ]);
    $route = null;

    if ($createRoute) {
        $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
            projectId: $project->id,
            domain: $domain,
            publication: $publication,
            instanceId: $instance->id,
            nodeId: null,
            clusterId: null,
        ))['route'];
        $route->update(['status' => RouteStatus::Active]);
    }

    return [$cluster, $router->refresh(), $ingress->refresh(), $workload->refresh(), $instance->refresh(), $route?->refresh()];
}

/** @return array{Cluster, Node} */
function route_cluster(string $name, ?string $tld): array
{
    static $octet = 100;
    $octet++;
    $cluster = Cluster::query()->create(['name' => $name, 'tld' => $tld, 'state' => ClusterState::Active]);
    $router = route_node("{$name}-router", "10.45.0.{$octet}", null);
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
