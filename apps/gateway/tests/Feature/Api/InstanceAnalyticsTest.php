<?php

declare(strict_types=1);

use App\Actions\AppInstances\RemoveAppInstanceAction;
use App\Domain\Analytics\AnalyticsTrackingRouteProjector;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteAnalyticsTracking;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAnalyticsTrackingRouteProjector;
use Tests\Support\FakePublicRouteEdgeProjector;
use Tests\Support\FakeRouteRemovalProjector;

beforeEach(function (): void {
    $this->projector = new FakeAnalyticsTrackingRouteProjector;
    $this->edge = new FakePublicRouteEdgeProjector;
    $this->removal = new FakeRouteRemovalProjector;
    app()->instance(AnalyticsTrackingRouteProjector::class, $this->projector);
    app()->instance(PublicRouteEdgeProjector::class, $this->edge);
    app()->instance(RouteRemovalProjector::class, $this->removal);
    app()->instance(DevelopmentProjectionOperationLock::class, new InstanceAnalyticsProjectionOwner);

    $this->gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);

    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Shop',
        'slug' => 'shop',
        'repository_url' => 'https://example.test/shop.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
    $this->router = instance_analytics_node('edge-router', '10.44.0.20', $this->cluster, RoleName::Router);
    $this->ingress = instance_analytics_node('edge-ingress', '10.44.0.30', $this->cluster, RoleName::Ingress);
    $this->workload = instance_analytics_node('edge-prod', '10.44.0.31', $this->cluster, RoleName::AppProd);
    $this->analytics = instance_analytics_node('services', '10.44.0.40', null, RoleName::Analytics);
    $this->instance = instance_analytics_instance($this->orbitApp, $this->workload, 'production');
    $this->appRoute = instance_analytics_app_route($this->instance, 'shop.example.com', RoutePublication::Public);
    $this->url = "/api/v1/instances/{$this->instance->id}/analytics";
});

describe('instance:analytics:show', function (): void {
    it('reports an App instance without tracking', function (): void {
        $this->getJson($this->url)
            ->assertOk()
            ->assertJsonPath('data', [
                'instance_id' => $this->instance->id,
                'enabled' => false,
                'domain' => 'shop.example.com',
                'dashboard_url' => 'https://analytics.orbit',
                'hosts' => [],
                'snippet' => null,
            ])
            ->assertJsonStructure(['meta' => ['request_id']]);
    });

    it('reports no dashboard without an active analytics role and no domain without a Route', function (): void {
        $this->analytics->roles()->update(['status' => LifecycleStatus::Provisioning]);
        $bare = instance_analytics_extra_instance('bare');

        $this->getJson("/api/v1/instances/{$bare->id}/analytics")
            ->assertOk()
            ->assertJsonPath('data.instance_id', $bare->id)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.domain', null)
            ->assertJsonPath('data.dashboard_url', null)
            ->assertJsonPath('data.hosts', [])
            ->assertJsonPath('data.snippet', null);
    });

    it('answers 404 for an unknown App instance', function (): void {
        $this->getJson('/api/v1/instances/999999/analytics')->assertNotFound();
    });
});

describe('instance:analytics:enable', function (): void {
    it('publishes the default host as a public cluster-scoped tracking Route', function (): void {
        $response = $this->postJson($this->url)->assertOk();
        $route = Route::query()->where('kind', RouteKind::AnalyticsTracking->value)->sole();

        expect($response->json('data'))->toBe([
            'instance_id' => $this->instance->id,
            'enabled' => true,
            'domain' => 'shop.example.com',
            'dashboard_url' => 'https://analytics.orbit',
            'hosts' => [
                [
                    'host' => 'analytics.shop.example.com',
                    'route_id' => $route->id,
                    'status' => 'active',
                    'publication' => 'public',
                    'failed_step' => null,
                    'error_code' => null,
                    'script_url' => 'https://analytics.shop.example.com/js/script.js',
                    'event_url' => 'https://analytics.shop.example.com/api/event',
                    'dns' => [
                        'type' => 'CNAME',
                        'name' => 'analytics.shop.example.com',
                        'value' => 'shop.example.com',
                    ],
                ],
            ],
            'snippet' => '<script defer data-domain="shop.example.com" src="https://analytics.shop.example.com/js/script.js"></script>',
        ]);

        expect($route->domain)->toBe('analytics.shop.example.com')
            ->and($route->app_id)->toBeNull()
            ->and($route->node_id)->toBeNull()
            ->and($route->cluster_id)->toBe($this->cluster->id)
            ->and($route->publication)->toBe(RoutePublication::Public)
            ->and($route->status)->toBe(RouteStatus::Active)
            ->and($route->replacement_step)->toBe(RouteReplacementStep::IngressFirewall)
            ->and($route->targets()->count())->toBe(0)
            ->and(RouteAnalyticsTracking::query()->sole()->getAttributes())
            ->toMatchArray(['route_id' => $route->id, 'app_instance_id' => $this->instance->id])
            ->and($this->projector->routeIds)->toBe([$route->id])
            ->and($this->edge->calls)->toBe([
                'ingress-certificate',
                'public-edge-verified',
                'public-activated',
                'ingress-firewall',
            ]);

        $this->getJson($this->url)->assertOk()->assertJsonPath('data', $response->json('data'));
        $this->getJson("/api/v1/routes/{$route->id}")
            ->assertOk()
            ->assertJsonPath('data.kind', 'analytics_tracking')
            ->assertJsonPath('data.analytics_instance_id', $this->instance->id)
            ->assertJsonPath('data.target', null)
            ->assertJsonPath('data.upstream', null);
        $this->getJson("/api/v1/routes/{$this->appRoute->id}")
            ->assertOk()
            ->assertJsonPath('data.analytics_instance_id', null);
    });

    it('publishes a public tracking host through Ingress and Router when both roles sit on the App instance Node', function (): void {
        $this->router->roles()->delete();
        $this->ingress->roles()->delete();

        foreach ([RoleName::Router, RoleName::Ingress] as $role) {
            $this->workload->roles()->create([
                'cluster_id' => $this->cluster->id,
                'role' => $role,
                'status' => LifecycleStatus::Active,
            ]);
        }

        $this->postJson($this->url)
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.hosts.0.host', 'analytics.shop.example.com')
            ->assertJsonPath('data.hosts.0.status', 'active')
            ->assertJsonPath('data.hosts.0.publication', 'public');

        $route = Route::query()->where('kind', RouteKind::AnalyticsTracking->value)->sole();

        expect($this->appRoute->refresh()->cluster_id)->toBe($this->cluster->id)
            ->and($this->appRoute->node_id)->toBeNull()
            ->and($this->appRoute->publication)->toBe(RoutePublication::Public)
            ->and($route->cluster_id)->toBe($this->cluster->id)
            ->and($route->node_id)->toBeNull()
            ->and($route->publication)->toBe(RoutePublication::Public)
            ->and($route->status)->toBe(RouteStatus::Active)
            ->and($this->edge->calls)->toBe([
                'ingress-certificate',
                'public-edge-verified',
                'public-activated',
                'ingress-firewall',
            ]);
    });

    it('treats an empty host list as the default host', function (): void {
        $this->postJson($this->url, ['hosts' => []])
            ->assertOk()
            ->assertJsonPath('data.hosts.0.host', 'analytics.shop.example.com')
            ->assertJsonCount(1, 'data.hosts');
    });

    it('is idempotent', function (): void {
        $first = $this->postJson($this->url)->assertOk()->json('data');
        $this->edge->calls = [];

        $this->postJson($this->url)->assertOk()->assertJsonPath('data', $first);

        expect(Route::query()->where('kind', RouteKind::AnalyticsTracking->value)->count())->toBe(1)
            ->and($this->projector->routeIds)->toHaveCount(1)
            ->and($this->edge->calls)->toBe([])
            ->and($this->removal->events)->toBe([]);
    });

    it('sets the exact host set: it adds the missing hosts and removes the others', function (): void {
        $this->postJson($this->url, ['hosts' => ['Stats.Shop.Example.com', 'analytics.shop.example.com']])
            ->assertOk()
            ->assertJsonPath('data.hosts.0.host', 'stats.shop.example.com')
            ->assertJsonPath('data.hosts.1.host', 'analytics.shop.example.com')
            ->assertJsonPath(
                'data.snippet',
                '<script defer data-domain="shop.example.com" src="https://stats.shop.example.com/js/script.js"></script>',
            );
        $kept = Route::query()->where('domain', 'stats.shop.example.com')->sole();
        $dropped = Route::query()->where('domain', 'analytics.shop.example.com')->sole();

        $this->postJson($this->url, ['hosts' => ['stats.shop.example.com', 'plausible.shop.example.com']])
            ->assertOk()
            ->assertJsonCount(2, 'data.hosts')
            ->assertJsonPath('data.hosts.0.route_id', $kept->id)
            ->assertJsonPath('data.hosts.1.host', 'plausible.shop.example.com')
            ->assertJsonPath('data.hosts.1.publication', 'public');

        expect(Route::query()->whereKey($dropped->id)->exists())->toBeFalse()
            ->and(Route::query()->where('kind', RouteKind::AnalyticsTracking->value)->pluck('domain')->all())
            ->toBe(['stats.shop.example.com', 'plausible.shop.example.com'])
            ->and(RouteAnalyticsTracking::query()->count())->toBe(2)
            ->and($this->edge->calls)->toContain('remove-public-edge')
            ->and($this->removal->routeIds)->toBe(array_fill(0, 4, $dropped->id))
            ->and($this->removal->events)->toBe(['dns', 'caddy', 'certificates', 'firewall']);
    });

    it('stays off the public edge without error while the cluster has no active Ingress', function (): void {
        $this->ingress->roles()->update(['status' => LifecycleStatus::Provisioning]);

        $this->postJson($this->url)
            ->assertOk()
            ->assertJsonPath('data.hosts.0.status', 'active')
            ->assertJsonPath('data.hosts.0.publication', 'public');

        $this->ingress->roles()->update(['status' => LifecycleStatus::Active]);

        $this->postJson($this->url)
            ->assertOk()
            ->assertJsonPath('data.hosts.0.publication', 'public');
        expect($this->projector->routeIds)->toHaveCount(1);
    });

    it('records a failed projection on the host and converges it on the next request', function (): void {
        $this->projector->failures = 1;

        $this->postJson($this->url)->assertStatus(502);
        $this->getJson($this->url)
            ->assertOk()
            ->assertJsonPath('data.hosts.0.status', 'failed')
            ->assertJsonPath('data.hosts.0.failed_step', 'projection')
            ->assertJsonPath('data.hosts.0.error_code', 'route.test_projection')
            ->assertJsonPath('data.hosts.0.publication', 'public');

        $this->postJson($this->url)
            ->assertOk()
            ->assertJsonPath('data.hosts.0.status', 'active')
            ->assertJsonPath('data.hosts.0.failed_step', null)
            ->assertJsonPath('data.hosts.0.publication', 'public');
        expect(Route::query()->where('kind', RouteKind::AnalyticsTracking->value)->count())->toBe(1);
    });

    it('rolls back a publication that fails before activation and publishes it on the next request', function (): void {
        $this->edge->failures = ['public-edge-verified' => 1];

        $this->postJson($this->url)->assertStatus(502)->assertJsonPath('error.code', 'route.test_public-edge-verified');

        $route = Route::query()->where('kind', RouteKind::AnalyticsTracking->value)->sole();
        expect($route->status)->toBe(RouteStatus::Active)
            ->and($route->publication)->toBe(RoutePublication::Public)
            ->and($this->edge->calls)->toContain('rollback-public-edge');

        $this->postJson($this->url)
            ->assertOk()
            ->assertJsonPath('data.hosts.0.route_id', $route->id)
            ->assertJsonPath('data.hosts.0.publication', 'public');
        expect($route->refresh()->replacement_step)->toBe(RouteReplacementStep::IngressFirewall)
            ->and($this->projector->routeIds)->toBe([$route->id]);
    });

    it('refuses malformed hosts and creates nothing', function (mixed $hosts, string $field): void {
        $details = $this->postJson($this->url, ['hosts' => $hosts])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->json('error.details');

        expect(array_keys($details))->toContain($field);

        expect(Route::query()->where('kind', RouteKind::AnalyticsTracking->value)->count())->toBe(0)
            ->and($this->projector->routeIds)->toBe([]);
    })->with([
        'more than ten hosts' => [array_map(static fn (int $n): string => "a{$n}.shop.example.com", range(1, 11)), 'hosts'],
        'a string' => ['analytics.shop.example.com', 'hosts'],
        'a map' => [['first' => 'analytics.shop.example.com'], 'hosts'],
        'a number in the list' => [['analytics.shop.example.com', 12], 'hosts.1'],
        'a URL' => [['https://analytics.shop.example.com'], 'hosts.0'],
        'a path' => [['analytics.shop.example.com/js'], 'hosts.0'],
        'an IP address' => [['203.0.113.10'], 'hosts.0'],
        'an underscore' => [['analytics_host.example.com'], 'hosts.0'],
        'a single label' => [['analytics'], 'hosts.0'],
        'the reserved dashboard name' => [['analytics.orbit'], 'hosts.0'],
        'another orbit name' => [['tracking.shop.orbit'], 'hosts.0'],
        'duplicates after normalisation' => [['stats.shop.example.com', ' STATS.shop.example.com '], 'hosts'],
    ]);

    it('refuses an unsupported body key', function (): void {
        $this->postJson($this->url, ['host' => 'analytics.shop.example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');
        expect(Route::query()->where('kind', RouteKind::AnalyticsTracking->value)->count())->toBe(0);
    });

    it('refuses while no Node has an active analytics role', function (): void {
        $this->analytics->roles()->update(['status' => LifecycleStatus::Provisioning]);

        $this->postJson($this->url)
            ->assertConflict()
            ->assertJsonPath('error.code', 'analytics.role_missing');
        expect(Route::query()->where('kind', RouteKind::AnalyticsTracking->value)->count())->toBe(0)
            ->and(RouteAnalyticsTracking::query()->count())->toBe(0);
    });

    it('mirrors a private App Route, so the host is served beside the domain it follows', function (): void {
        $target = instance_analytics_extra_instance('private');
        $owner = instance_analytics_app_route($target, 'private.example.com', RoutePublication::Private);

        $this->postJson("/api/v1/instances/{$target->id}/analytics")
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.hosts.0.host', 'analytics.private.example.com')
            ->assertJsonPath('data.hosts.0.publication', 'private');

        $tracking = Route::query()
            ->where('kind', RouteKind::AnalyticsTracking->value)
            ->where('domain', 'analytics.private.example.com')
            ->sole();

        // The same scope and publication as the Route it follows, so the same edge serves both.
        expect($tracking->publication)->toBe(RoutePublication::Private)
            ->and($tracking->node_id)->toBe($owner->node_id)
            ->and($tracking->cluster_id)->toBe($owner->cluster_id)
            ->and($tracking->status)->toBe(RouteStatus::Active)
            ->and($tracking->publication)->toBe(RoutePublication::Private)
            ->and($this->projector->routeIds)->toContain($tracking->id)
            // A private host never reaches the public edge, so it publishes nothing there.
            ->and($this->edge->calls)->toBe([]);
    });

    it('refuses an App instance that serves no domain', function (Closure $instance): void {
        $target = $instance();

        $this->postJson("/api/v1/instances/{$target->id}/analytics", ['hosts' => ['stats.shop.example.com']])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'analytics.domain_required');
        expect(Route::query()->where('kind', RouteKind::AnalyticsTracking->value)->count())->toBe(0)
            ->and($this->projector->routeIds)->toBe([]);
    })->with([
        'no Route' => [fn (): AppInstance => instance_analytics_extra_instance('bare')],
        'a Route that is not authoritative' => [function (): AppInstance {
            $pending = instance_analytics_extra_instance('pending');
            instance_analytics_app_route($pending, 'pending.example.com', RoutePublication::Public, activate: false);

            return $pending;
        }],
    ]);

    it('refuses a host that another Route owns and creates none of the requested hosts', function (): void {
        $other = instance_analytics_extra_instance('other');
        instance_analytics_app_route($other, 'other.example.com', RoutePublication::Public);
        $this->postJson("/api/v1/instances/{$other->id}/analytics", ['hosts' => ['stats.example.com']])->assertOk();
        $this->projector->routeIds = [];

        foreach (['other.example.com', 'stats.example.com', 'shop.example.com'] as $taken) {
            $this->postJson($this->url, ['hosts' => ['fresh.shop.example.com', $taken]])
                ->assertConflict()
                ->assertJsonPath('error.code', 'analytics.host_taken');
        }

        expect(Route::query()->where('domain', 'fresh.shop.example.com')->exists())->toBeFalse()
            ->and(RouteAnalyticsTracking::query()->where('app_instance_id', $this->instance->id)->count())->toBe(0)
            ->and($this->projector->routeIds)->toBe([]);
    });

    it('keeps the generic Route create from taking a tracking host', function (): void {
        $this->postJson($this->url)->assertOk();

        $this->postJson('/api/v1/routes', [
            'app_id' => $this->orbitApp->id,
            'domain' => 'analytics.shop.example.com',
            'publication' => 'public',
            'cluster_id' => $this->cluster->id,
        ])->assertConflict()->assertJsonPath('error.code', 'route.domain_conflict');
        $this->postJson('/api/v1/routes', [
            'domain' => 'analytics.shop.example.com',
            'node_id' => $this->workload->id,
            'upstream' => 'http://127.0.0.1:4788',
        ])->assertConflict()->assertJsonPath('error.code', 'route.domain_conflict');
    });
});

describe('instance:analytics:disable', function (): void {
    it('removes every tracking Route of the App instance and is idempotent', function (): void {
        $other = instance_analytics_extra_instance('other');
        instance_analytics_app_route($other, 'other.example.com', RoutePublication::Public);
        $this->postJson("/api/v1/instances/{$other->id}/analytics")->assertOk();
        $this->postJson($this->url, ['hosts' => ['analytics.shop.example.com', 'stats.shop.example.com']])->assertOk();
        $this->edge->calls = [];

        $expected = [
            'instance_id' => $this->instance->id,
            'enabled' => false,
            'domain' => 'shop.example.com',
            'dashboard_url' => 'https://analytics.orbit',
            'hosts' => [],
            'snippet' => null,
        ];

        $this->deleteJson($this->url)->assertOk()->assertJsonPath('data', $expected);

        expect(Route::query()->where('kind', RouteKind::AnalyticsTracking->value)->pluck('domain')->all())
            ->toBe(['analytics.other.example.com'])
            ->and(RouteAnalyticsTracking::query()->pluck('app_instance_id')->all())->toBe([$other->id])
            ->and($this->edge->calls)->toBe(['remove-public-edge', 'remove-public-edge'])
            ->and($this->removal->events)->toBe([
                'dns', 'caddy', 'certificates', 'firewall',
                'dns', 'caddy', 'certificates', 'firewall',
            ])
            ->and(Route::query()->whereKey($this->appRoute->id)->exists())->toBeTrue();

        $this->edge->calls = [];
        $this->removal->events = [];
        $this->deleteJson($this->url)->assertOk()->assertJsonPath('data', $expected);
        expect($this->edge->calls)->toBe([])->and($this->removal->events)->toBe([]);
    });
});

describe('generic Route commands on a tracking Route', function (): void {
    it('cannot create the kind through route:create', function (): void {
        $this->postJson('/api/v1/routes', [
            'kind' => 'analytics_tracking',
            'domain' => 'analytics.shop.example.com',
            'publication' => 'public',
            'cluster_id' => $this->cluster->id,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

        $this->postJson('/api/v1/routes', [
            'domain' => 'analytics.shop.example.com',
            'publication' => 'public',
            'cluster_id' => $this->cluster->id,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

        expect(Route::query()->where('domain', 'analytics.shop.example.com')->exists())->toBeFalse();
    });

    it('refuses route:update and both route:target commands', function (): void {
        $routeId = $this->postJson($this->url)->assertOk()->json('data.hosts.0.route_id');

        $this->patchJson("/api/v1/routes/{$routeId}", ['publication' => 'private'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.kind_unsupported');
        $this->patchJson("/api/v1/routes/{$routeId}", ['domain' => 'moved.shop.example.com'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.kind_unsupported');
        $this->putJson("/api/v1/routes/{$routeId}/target", ['app_instance_id' => $this->instance->id])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.kind_unsupported');
        $this->deleteJson("/api/v1/routes/{$routeId}/target")
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.kind_unsupported');

        $route = Route::query()->findOrFail($routeId);
        expect($route->domain)->toBe('analytics.shop.example.com')
            ->and($route->publication)->toBe(RoutePublication::Public)
            ->and($route->targets()->count())->toBe(0);
    });

    it('lists the tracking Route with its App instance', function (): void {
        $routeId = $this->postJson($this->url)->assertOk()->json('data.hosts.0.route_id');

        $this->getJson('/api/v1/routes')
            ->assertOk()
            ->assertJsonPath('data.0.id', $routeId)
            ->assertJsonPath('data.0.kind', 'analytics_tracking')
            ->assertJsonPath('data.0.analytics_instance_id', $this->instance->id)
            ->assertJsonPath('data.1.analytics_instance_id', null);
    });
});

describe('guards around a tracking host', function (): void {
    it('refuses to remove the analytics role while a tracking host exists', function (): void {
        $this->postJson($this->url)->assertOk();

        $this->deleteJson("/api/v1/nodes/{$this->analytics->id}/roles/analytics", ['force' => true])
            ->assertConflict()
            ->assertJsonPath('error.code', 'analytics.tracking_hosts_exist');
        expect($this->analytics->roles()->where('role', RoleName::Analytics->value)->exists())->toBeTrue();
    });

    it('refuses to remove an App instance that still publishes a tracking host', function (): void {
        $this->postJson($this->url)->assertOk();

        expect(fn () => app(RemoveAppInstanceAction::class)->execute($this->instance->refresh(), force: true))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('analytics.tracking_hosts_exist');
            });
        expect(DB::table('app_instance_removals')->count())->toBe(0)
            ->and(RouteAnalyticsTracking::query()->count())->toBe(1);
    });
});
