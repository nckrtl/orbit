<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;

function browserOriginRoute(string $domain, bool $activate = true): Route
{
    static $sequence = 0;
    $sequence++;
    $app = OrbitApp::query()->create([
        'name' => "Shop {$sequence}",
        'slug' => "shop-{$sequence}",
        'repository_url' => "https://example.test/shop-{$sequence}.git",
    ]);
    $node = Node::query()->create([
        'name' => "workload-{$sequence}",
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => "workload-{$sequence}.test",
        'wireguard_ip' => '10.44.0.'.(100 + $sequence),
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'dev',
        'environment' => 'development',
        'checkout_path' => "/srv/shop-{$sequence}",
        'source_is_laravel' => false,
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'domain' => $domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);

    if ($activate) {
        $route->update(['status' => RouteStatus::Active]);
    }

    return $route->refresh();
}

beforeEach(function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    browserOriginRoute('shop.test');
    browserOriginRoute('pending.test', activate: false);
    $public = browserOriginRoute('shop.example');
    $cluster = Cluster::query()->create(['name' => 'public-cluster', 'state' => ClusterState::Active]);
    foreach ([RoleName::Router, RoleName::Ingress] as $index => $role) {
        $node = Node::query()->create([
            'cluster_id' => $cluster->id,
            'name' => "edge-{$role->value}",
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.'.(210 + $index),
            'wireguard_ip' => '10.44.0.'.(210 + $index),
            'user' => 'orbit',
        ]);
        $node->roles()->create(['cluster_id' => $cluster->id, 'role' => $role, 'status' => LifecycleStatus::Active]);
    }
    $public->update(['node_id' => null, 'cluster_id' => $cluster->id, 'publication' => RoutePublication::Public]);
});

it('leaves clients without browser markers unchanged', function (): void {
    $this->getJson('/api/v1/nodes')->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('admits a page on the requested origin', function (): void {
    $origin = rtrim((string) config('app.url'), '/');

    $this->postJson('/api/v1/nodes', [], ['Origin' => $origin, 'Sec-Fetch-Site' => 'same-origin'])
        ->assertStatus(422);
});

it('admits same-origin pages and the Gateway site', function (array $headers): void {
    $this->getJson('/api/v1/nodes', $headers)->assertOk();
})->with([
    'same-origin fetch without Origin' => [['Sec-Fetch-Site' => 'same-origin']],
    'direct navigation' => [['Sec-Fetch-Site' => 'none']],
    'Gateway origin from the Metrics hop' => [['Origin' => 'https://gateway.orbit', 'Sec-Fetch-Site' => 'same-origin']],
]);

it('answers CORS with the exact origin of an active private App Route', function (): void {
    $this->getJson('/api/v1/nodes', ['Origin' => 'https://shop.test', 'Sec-Fetch-Site' => 'cross-site'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://shop.test')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');

    $this->call('OPTIONS', '/api/v1/nodes', server: [
        'HTTP_ORIGIN' => 'https://shop.test:443',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_SEC_FETCH_SITE' => 'cross-site',
    ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://shop.test:443');
});

it('refuses other origins before routing, including preflight and MCP', function (string $method, string $uri, array $server): void {
    $this->call($method, $uri, server: [...$server, 'HTTP_ACCEPT' => 'application/json'])
        ->assertForbidden()
        ->assertExactJson(['error' => [
            'code' => 'api.origin_refused',
            'message' => 'Browser requests from this origin cannot reach the Gateway API.',
        ]])
        ->assertHeaderMissing('Access-Control-Allow-Origin');
})->with([
    'unknown site' => ['GET', '/api/v1/metrics/credentials', ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site']],
    'unknown preflight' => ['OPTIONS', '/api/v1/nodes', ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'DELETE']],
    'simple cross-site POST' => ['POST', '/api/v1/nodes', ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'CONTENT_TYPE' => 'text/plain']],
    'opaque origin' => ['POST', '/api/v1/nodes', ['HTTP_ORIGIN' => 'null']],
    'cross-site link without Origin' => ['GET', '/api/v1/nodes', ['HTTP_SEC_FETCH_SITE' => 'cross-site']],
    'same-site subdomain' => ['GET', '/api/v1/nodes', ['HTTP_ORIGIN' => 'https://other.orbit', 'HTTP_SEC_FETCH_SITE' => 'same-site']],
    'public Route' => ['GET', '/api/v1/nodes', ['HTTP_ORIGIN' => 'https://shop.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site']],
    'Route not active' => ['GET', '/api/v1/nodes', ['HTTP_ORIGIN' => 'https://pending.test', 'HTTP_SEC_FETCH_SITE' => 'cross-site']],
    'plain http Route origin' => ['GET', '/api/v1/nodes', ['HTTP_ORIGIN' => 'http://shop.test', 'HTTP_SEC_FETCH_SITE' => 'cross-site']],
    'MCP endpoint' => ['POST', '/mcp', ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site']],
]);

it('does not guard paths outside the API and MCP', function (): void {
    $this->get('/up', ['Origin' => 'https://evil.example', 'Sec-Fetch-Site' => 'cross-site'])->assertOk();
});
