<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills the publication record for Routes that serve their sites', function (): void {
    $migration = store_route_site_transitions_migration();
    $migration->down();

    expect(Schema::hasColumns('routes', ['sites_published', 'transition_node_id', 'transition_cluster_id']))
        ->toBeFalse();

    $node = store_route_site_transitions_node();
    $active = store_route_site_transitions_route($node, 'active', RouteStatus::Active);
    $activating = store_route_site_transitions_route($node, 'activating', RouteStatus::Activating);
    $retiring = store_route_site_transitions_route($node, 'retiring', RouteStatus::Retiring);
    $pending = store_route_site_transitions_route($node, 'pending', RouteStatus::Pending);
    $failed = store_route_site_transitions_route($node, 'failed', RouteStatus::Failed);
    // An interrupted Instance removal already deleted the last target of this authoritative Route.
    $interrupted = store_route_site_transitions_route($node, 'interrupted', RouteStatus::Active);
    DB::table('app_instances')
        ->whereIn('id', DB::table('route_targets')->where('route_id', $interrupted)->select('app_instance_id'))
        ->update(['status' => AppInstanceState::SourceResolved->value]);
    DB::table('route_targets')->where('route_id', $interrupted)->delete();

    $migration->up();

    $published = static fn (int $id): bool => (bool) DB::table('routes')->where('id', $id)->value('sites_published');

    expect($published($active))->toBeTrue()
        ->and($published($activating))->toBeTrue()
        ->and($published($retiring))->toBeTrue()
        ->and($published($pending))->toBeFalse()
        ->and($published($failed))->toBeFalse()
        ->and($published($interrupted))->toBeFalse()
        ->and(DB::table('routes')->whereNotNull('transition_node_id')->orWhereNotNull('transition_cluster_id')->exists())
        ->toBeFalse();
});

it('refuses to drop an unfinished placement transition', function (): void {
    $node = store_route_site_transitions_node();
    $route = store_route_site_transitions_route($node, 'moving', RouteStatus::Active);
    DB::table('routes')->where('id', $route)->update(['transition_node_id' => $node->id]);

    expect(fn () => store_route_site_transitions_migration()->down())
        ->toThrow(RuntimeException::class, "Cannot remove Route placement transitions while they are unfinished: {$route}.");
});

function store_route_site_transitions_migration(): Migration
{
    return require base_path('database/migrations/2026_09_24_120000_store_route_site_transitions.php');
}

function store_route_site_transitions_node(): Node
{
    return Node::query()->create([
        'name' => 'workload',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10',
        'user' => 'orbit',
    ]);
}

/** Writes the Route through the query builder, as a Gateway before this migration would have. */
function store_route_site_transitions_route(Node $node, string $name, RouteStatus $status): int
{
    $app = OrbitApp::query()->create([
        'name' => $name,
        'slug' => "backfill-{$name}",
        'repository_url' => "https://example.test/{$name}.git",
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/home/orbit/apps/{$name}",
        'status' => AppInstanceState::Active,
    ]);
    $id = DB::table('routes')->insertGetId([
        'kind' => 'app',
        'app_id' => $app->id,
        'node_id' => $node->id,
        'domain' => "{$name}.backfill.test",
        'provenance' => RouteProvenance::Explicit->value,
        'publication' => RoutePublication::Private->value,
        'status' => RouteStatus::Pending->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('route_targets')->insert([
        'route_id' => $id,
        'app_instance_id' => $instance->id,
        'position' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $attributes = ['status' => $status->value];

    if ($status === RouteStatus::Failed) {
        $attributes += ['failed_step' => 'projection', 'error_code' => 'route.projection_failed'];
    }

    if ($status === RouteStatus::Activating) {
        $attributes += ['replacement_step' => RouteReplacementStep::DatabaseCutover->value];
    }

    DB::table('routes')->where('id', $id)->update($attributes);

    return $id;
}
