<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;

it('renders managed header and terminal newline', function (): void {
    $result = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render();
    expect($result)->toStartWith('# Managed by Orbit.')->toEndWith("\n");
});

it('projects a Cluster-scoped Route to the Router WireGuard address', function (): void {
    $route = orb258_cluster_route();

    $configuration = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render();

    expect($configuration)
        ->toContain("host-record={$route->domain},10.44.0.20")
        ->not
        ->toContain("host-record={$route->domain},10.44.0.10")
        ->not
        ->toContain("host-record={$route->domain},192.168.10.20");
});

it('projects a Node-scoped Route to the workload WireGuard address', function (): void {
    $node = Node::query()->create([
        'name' => 'solo-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'solo.test',
        'public_ssh_host' => '192.0.2.40',
        'wireguard_ip' => '10.44.0.40',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $app = OrbitApp::query()->create([
        'name' => 'Solo',
        'slug' => 'solo',
        'repository_url' => 'https://example.test/solo.git',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'checkout_path' => '/home/orbit/apps/solo',
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('c', 40),
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'domain' => 'solo.app.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'app_instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);

    $configuration = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render();

    expect($configuration)
        ->toContain('host-record=solo.app.test,10.44.0.40')
        ->toContain('address=/.solo.test/10.44.0.40');
});

it('keeps gateway.orbit and metrics.orbit on the Gateway WireGuard address', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $gateway
        ->roles()
        ->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);
    $metrics = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.3',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $metrics
        ->roles()
        ->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Provisioning,
        ]);

    $configuration = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render($metrics);

    expect($configuration)->toContain('host-record=metrics.orbit,10.44.0.1')
        ->toContain('host-record=gateway.orbit,10.44.0.1');
});

it('projects an active Cluster TLD to the Router WireGuard address', function (): void {
    $route = orb258_cluster_route();
    $route->cluster->update(['tld' => 'cluster.test']);
    $route->targets->first()->appInstance->node->update(['tld' => 'cluster.test']);

    $configuration = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render();

    expect($configuration)
        ->toContain('address=/.cluster.test/10.44.0.20')
        ->not
        ->toContain('address=/.cluster.test/10.44.0.10');
});

it('builds a requester catalog from the same records the renderer publishes', function (): void {
    $route = orb258_cluster_route();
    $renderer = new AppDevDnsConfigRenderer(new AppDevSiteRepository);

    $configuration = $renderer->render();
    $catalog = $renderer->catalog();

    expect($catalog->exact[$route->domain])
        ->toBe('10.44.0.20')
        ->and($catalog->suffixes)
        ->toHaveKey('cluster-app.test')
        ->and($catalog->overrides)
        ->toBeEmpty()
        ->and($renderer->registeredRequesters())
        ->toBe([
            '10.44.0.10' => $route->targets->first()->appInstance->node_id,
            '10.44.0.20' => $route->cluster->routerAssignment->node_id,
        ])
        ->and($configuration)
        ->toBe($renderer->render());
});

function orb258_cluster_route(): Route
{
    $cluster = Cluster::query()->create([
        'name' => 'dns-cluster',
        'state' => ClusterState::Active,
    ]);
    $workload = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'cluster-workload',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'cluster-app.test',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10',
        'lan_ip' => '192.168.10.10',
        'user' => 'orbit',
    ]);
    $workload->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $router = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'cluster-router',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.20',
        'lan_ip' => '192.168.10.20',
        'user' => 'orbit',
    ]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Clustered',
        'slug' => 'clustered',
        'repository_url' => 'https://example.test/clustered.git',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $workload->id,
        'name' => 'default',
        'checkout_path' => '/home/orbit/apps/clustered',
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('d', 40),
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'domain' => 'app.cluster.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'app_instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->fresh(['targets.appInstance', 'cluster.routerAssignment.node']);
}
