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
use Illuminate\Support\Str;

it('keeps the host record for a node-scoped production Route', function (): void {
    [$instance, $route, $node] = production_route_site_models();
    $sites = new AppDevSiteRepository;

    $site = $sites->all()->sole();
    $dns = new AppDevDnsConfigRenderer($sites)->render();

    expect($site->scope)
        ->toBe("app-instance-{$instance->id}")
        ->and($site->nodeId)
        ->toBe($node->id)
        ->and($site->isProxy())
        ->toBeFalse()
        ->and($dns)
        ->toContain("host-record={$route->hostname},{$node->wireguard_ip}");
});

it('keeps the host record when a production target also owns the Router role', function (): void {
    $cluster = Cluster::query()->create([
        'name' => 'production-'.Str::lower(Str::random(8)),
        'state' => ClusterState::Active,
    ]);
    [$instance, $route, $node] = production_route_site_models($cluster);
    $node->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);
    $sites = new AppDevSiteRepository;

    $site = $sites->all()->sole();
    $dns = new AppDevDnsConfigRenderer($sites)->render();

    expect($site->scope)
        ->toBe("app-instance-{$instance->id}")
        ->and($site->nodeId)
        ->toBe($node->id)
        ->and($site->isProxy())
        ->toBeFalse()
        ->and($sites->all()->where('scope', "route-{$route->id}-router"))
        ->toBeEmpty()
        ->and($dns)
        ->toContain("host-record={$route->hostname},{$node->wireguard_ip}");
});

/** @return array{AppInstance, Route, Node} */
function production_route_site_models(?Cluster $cluster = null): array
{
    $suffix = Str::lower(Str::random(8));
    $app = OrbitApp::query()->create([
        'name' => "Production {$suffix}",
        'slug' => "production-{$suffix}",
        'repository_url' => "https://example.test/production-{$suffix}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'cluster_id' => $cluster?->id,
        'name' => "production-{$suffix}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'wireguard_ip' => '10.44.0.80',
        'user' => 'orbit',
    ]);
    $node->roles()->create([
        'role' => RoleName::AppProd,
        'status' => LifecycleStatus::Active,
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/home/orbit/apps/{$app->slug}/production",
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $cluster instanceof Cluster ? null : $node->id,
        'cluster_id' => $cluster?->id,
        'generation_basis_node_id' => $cluster instanceof Cluster ? null : $node->id,
        'hostname' => "production-{$suffix}.test",
        'provenance' => $cluster instanceof Cluster ? RouteProvenance::Explicit : RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route
        ->targets()
        ->create([
            'app_instance_id' => $instance->id,
            'position' => 0,
        ]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);

    return [$instance, $route, $node];
}
