<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsStatsDriver;
use App\Domain\Analytics\AnalyticsStatsRead;
use App\Domain\Analytics\AnalyticsTrackingRouteProjector;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\Cluster;
use App\Models\Node;
use Tests\Support\FakeAnalyticsStatsDriver;
use Tests\Support\FakeAnalyticsTrackingRouteProjector;
use Tests\Support\FakePublicRouteEdgeProjector;
use Tests\Support\FakeRouteRemovalProjector;

beforeEach(function (): void {
    $this->driver = new FakeAnalyticsStatsDriver;
    app()->instance(AnalyticsStatsDriver::class, $this->driver);
    app()->instance(AnalyticsTrackingRouteProjector::class, new FakeAnalyticsTrackingRouteProjector);
    app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
    app()->instance(RouteRemovalProjector::class, new FakeRouteRemovalProjector);
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
    $this->url = "/api/v1/instances/{$this->instance->id}/analytics/stats";
});

describe('instance:analytics:stats', function (): void {
    it('hides the report when the App instance has no tracking host', function (): void {
        $this->getJson($this->url)
            ->assertOk()
            ->assertJsonPath('data', ['available' => false]);

        expect($this->driver->sites)->toBe([]);
    });

    it('hides the report when tracking is enabled but the analytics role is not active', function (): void {
        $this->postJson("/api/v1/instances/{$this->instance->id}/analytics")->assertOk();
        $this->driver->healthy = false;

        $this->getJson($this->url)
            ->assertOk()
            ->assertJsonPath('data', ['available' => false]);

        expect($this->driver->sites)->toBe([]);
    });

    it('returns live visitors, period counts, and top pages for the instance domain', function (): void {
        $this->postJson("/api/v1/instances/{$this->instance->id}/analytics")->assertOk();

        $this->getJson($this->url)
            ->assertOk()
            ->assertJsonPath('data', [
                'available' => true,
                'readable' => true,
                'driver' => 'plausible_ce',
                'site_domain' => 'shop.example.com',
                'live_visitors' => 2,
                'visitors' => [
                    'past_24h' => 18,
                    'past_7d' => 91,
                    'past_30d' => 340,
                ],
                'pages' => [
                    ['path' => '/', 'visitors' => 120],
                    ['path' => '/pricing', 'visitors' => 40],
                ],
            ]);

        expect($this->driver->sites)->toBe(['shop.example.com']);
    });

    it('returns a readable false report with no visitor numbers when the driver cannot read stats', function (): void {
        $this->postJson("/api/v1/instances/{$this->instance->id}/analytics")->assertOk();
        $this->driver->read = AnalyticsStatsRead::failed(
            'shop.example.com',
            'analytics.stats_key_missing',
            'No Plausible Stats API key is stored.',
        );

        $response = $this->getJson($this->url)->assertOk();

        expect($response->json('data'))->toBe([
            'available' => true,
            'readable' => false,
            'driver' => 'plausible_ce',
            'site_domain' => 'shop.example.com',
            'error_code' => 'analytics.stats_key_missing',
            'error' => 'No Plausible Stats API key is stored.',
        ])
            ->and($response->json('data'))->not->toHaveKey('live_visitors')
            ->and($response->json('data'))->not->toHaveKey('visitors')
            ->and($response->json('data'))->not->toHaveKey('pages');
    });

    it('maps the App instance domain, not a client-supplied site', function (): void {
        $this->postJson("/api/v1/instances/{$this->instance->id}/analytics")->assertOk();

        $this->getJson($this->url.'?site_id=other.example.com')->assertOk();

        expect($this->driver->sites)->toBe(['shop.example.com']);
    });

    it('answers 404 for an unknown App instance', function (): void {
        $this->getJson('/api/v1/instances/999999/analytics/stats')->assertNotFound();
    });
});
