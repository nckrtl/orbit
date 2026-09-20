<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('keeps a main-shaped live public Route live after dropping public_publication', function (): void {
    Schema::table('routes', static function (Blueprint $table): void {
        $table->string('public_publication')->default('inactive');
    });

    [$cluster, $active] = drop_public_publication_app_route('hauzer', RouteStatus::Active);
    [, $activating] = drop_public_publication_app_route('mealou', RouteStatus::Activating, $cluster);
    [, $neverActivated] = drop_public_publication_app_route('horizon', RouteStatus::Active, $cluster);
    $tracking = drop_public_publication_tracking_route($cluster, 'analytics.dlf.example.test');
    $private = drop_public_publication_private_route($cluster);

    DB::table('routes')->whereIn('id', [$active->id, $activating->id, $tracking->id])->update([
        'public_publication' => 'active',
        'replacement_step' => null,
    ]);
    DB::table('routes')->where('id', $neverActivated->id)->update([
        'public_publication' => 'inactive',
        'replacement_step' => null,
    ]);

    expect(new PublicRouteEligibility()->publicEdgeIsLive($active->refresh()))->toBeFalse();

    drop_public_publication_migration()->up();

    $eligibility = new PublicRouteEligibility;
    $active = drop_public_publication_reload($active);
    $activating = drop_public_publication_reload($activating);
    $tracking = drop_public_publication_reload($tracking);
    $neverActivated = drop_public_publication_reload($neverActivated);

    expect(Schema::hasColumn('routes', 'public_publication'))
        ->toBeFalse()
        ->and($active->replacement_step)
        ->toBe(RouteReplacementStep::IngressFirewall)
        ->and($eligibility->publicEdgeIsLive($active))
        ->toBeTrue()
        ->and($activating->replacement_step)
        ->toBe(RouteReplacementStep::IngressFirewall)
        ->and($eligibility->publicEdgeIsLive($activating))
        ->toBeTrue()
        ->and($tracking->replacement_step)
        ->toBe(RouteReplacementStep::IngressFirewall)
        ->and($eligibility->publicEdgeIsLive($tracking))
        ->toBeTrue()
        ->and($neverActivated->replacement_step)
        ->toBeNull()
        ->and($eligibility->publicEdgeIsLive($neverActivated))
        ->toBeFalse()
        ->and($private->refresh()->replacement_step)
        ->toBeNull();
});

function drop_public_publication_migration(): Migration
{
    return require base_path(
        'database/migrations/2026_09_20_180000_drop_public_publication_from_routes.php',
    );
}

/** @return array{Cluster, Route} */
function drop_public_publication_app_route(
    string $name,
    RouteStatus $status,
    ?Cluster $cluster = null,
): array {
    if (! $cluster instanceof Cluster) {
        $cluster = Cluster::query()->create(['name' => $name, 'state' => ClusterState::Active]);
        drop_public_publication_node($cluster, "{$name}-router", RoleName::Router);
        drop_public_publication_node($cluster, "{$name}-ingress", RoleName::Ingress);
    }

    $workload = drop_public_publication_node($cluster, "{$name}-prod", RoleName::AppProd);
    $app = OrbitApp::query()->create([
        'name' => $name,
        'slug' => $name,
        'repository_url' => "https://example.test/{$name}.git",
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $workload->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/srv/{$name}",
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'domain' => "{$name}.example.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => $status]);

    return [$cluster->refresh(), $route->refresh()];
}

function drop_public_publication_tracking_route(Cluster $cluster, string $domain): Route
{
    $route = Route::query()->create([
        'kind' => RouteKind::AnalyticsTracking,
        'cluster_id' => $cluster->id,
        'domain' => $domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}

function drop_public_publication_private_route(Cluster $cluster): Route
{
    $app = OrbitApp::query()->create([
        'name' => 'Private',
        'slug' => 'private-cutover',
        'repository_url' => 'https://example.test/private-cutover.git',
    ]);
    $workload = drop_public_publication_node($cluster, 'private-prod', RoleName::AppProd);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $workload->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/srv/private-cutover',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'domain' => 'private-cutover.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}

function drop_public_publication_node(Cluster $cluster, string $name, RoleName $role): Node
{
    static $octet = 40;
    $octet++;
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => "{$name}.test",
        'wireguard_ip' => "10.44.0.{$octet}",
        'lan_ip' => "10.10.0.{$octet}",
        'cluster_id' => $cluster->id,
    ]);
    $node->roles()->create([
        'cluster_id' => $role === RoleName::AppProd ? null : $cluster->id,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function drop_public_publication_reload(Route $route): Route
{
    return $route->refresh()->load(['cluster.routerAssignment.node', 'cluster.ingressAssignment.node']);
}
