<?php

declare(strict_types=1);

use App\Actions\Routes\CreateRouteAction;
use App\Data\Routes\CreateRouteData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;

function instance_analytics_node(string $name, string $wireguardIp, ?Cluster $cluster, RoleName $role): Node
{
    $octet = substr($wireguardIp, strrpos($wireguardIp, '.') + 1);
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => "192.0.2.{$octet}",
        'wireguard_ip' => $wireguardIp,
        'lan_ip' => $cluster instanceof Cluster ? "10.10.0.{$octet}" : null,
        'cluster_id' => $cluster?->id,
        'user' => 'orbit',
    ]);
    $node->roles()->create([
        'cluster_id' => in_array($role, [RoleName::Router, RoleName::Ingress], true) ? $cluster?->id : null,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function instance_analytics_instance(OrbitApp $app, Node $node, string $name): AppInstance
{
    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/srv/orbit/apps/{$app->slug}/{$name}",
        'branch' => $name,
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::Active,
        'environment' => 'production',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'production_home' => "/var/www/{$app->slug}-{$name}",
        'production_user' => "orbit-{$app->slug}",
        'selected_php_version' => '8.5',
    ]);
}

/** A Node holds one production App instance of an App, so every further instance gets a Node of its own. */
function instance_analytics_extra_instance(string $name): AppInstance
{
    static $octet = 60;
    $octet++;

    return instance_analytics_instance(
        test()->orbitApp,
        instance_analytics_node("edge-{$name}", "10.44.0.{$octet}", test()->cluster, RoleName::AppProd),
        $name,
    );
}

function instance_analytics_app_route(
    AppInstance $instance,
    string $domain,
    RoutePublication $publication,
    bool $activate = true,
): Route {
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $instance->app_id,
        domain: $domain,
        publication: $publication,
        appInstanceId: $instance->id,
        nodeId: null,
        clusterId: null,
    ))['route'];

    if ($activate) {
        $route->update(['status' => RouteStatus::Active]);
    }

    return $route->refresh();
}

final readonly class InstanceAnalyticsProjectionOwner implements DevelopmentProjectionOperationLock
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}
