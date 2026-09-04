<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('stores exclusive Route scope, immutable provenance, basis, and pending lifecycle', function (): void {
    expect(Schema::hasColumns('routes', [
        'app_id',
        'node_id',
        'cluster_id',
        'generation_basis_node_id',
        'hostname',
        'provenance',
        'publication',
        'status',
        'failed_step',
        'error_code',
    ]))->toBeTrue();

    $app = App\Models\App::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
    ]);
    $node = route_migration_node('one');
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => 'acme.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'generation_basis_node_id' => $node->id,
        'status' => RouteStatus::Pending,
    ]);

    expect(fn () => $route->update(['provenance' => RouteProvenance::Explicit]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('routes')->where('id', $route->id)->update(['status' => 'active']))
        ->toThrow(QueryException::class);
});

it('rejects duplicate target Nodes', function (): void {
    $app = App\Models\App::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
    ]);
    $nodeOne = route_migration_node('one');
    $one = route_migration_instance($app, $nodeOne, 'one');
    $duplicateNode = route_migration_instance($app, $nodeOne, 'duplicate');
    $explicit = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $nodeOne->id,
        'hostname' => 'explicit.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
    ]);
    $explicit->targets()->create(['app_instance_id' => $one->id, 'position' => 0]);

    expect(fn () => $explicit
        ->targets()
        ->create([
            'app_instance_id' => $duplicateNode->id,
            'position' => 1,
        ]))->toThrow(QueryException::class)->and($explicit->targets()->count())->toBe(1);
});

it('enforces multi-target storage rules with compatible Cluster-scoped explicit rows', function (): void {
    $app = App\Models\App::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
    ]);
    $cluster = App\Models\Cluster::query()->create(['name' => 'cluster', 'state' => 'active']);
    $oneNode = route_migration_node('one');
    $twoNode = route_migration_node('two');
    $oneNode->update(['cluster_id' => $cluster->id]);
    $twoNode->update(['cluster_id' => $cluster->id]);
    $one = route_migration_instance($app, $oneNode, 'one');
    $two = route_migration_instance($app, $twoNode, 'two');
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'hostname' => 'explicit.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
    ]);
    $route->targets()->create(['app_instance_id' => $two->id, 'position' => 1]);
    $route->targets()->create(['app_instance_id' => $one->id, 'position' => 0]);

    expect($route->targets()->pluck('position')->all())->toBe([0, 1]);

    $generated = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'generation_basis_node_id' => $oneNode->id,
        'hostname' => 'generated.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
    ]);
    $generated->targets()->create(['app_instance_id' => $one->id, 'position' => 0]);
    expect(fn () => $generated->targets()->create(['app_instance_id' => $two->id, 'position' => 1]))
        ->toThrow(QueryException::class);
});

function route_migration_node(string $name): App\Models\Node
{
    return App\Models\Node::query()->create([
        'name' => $name,
        'status' => 'active',
        'public_ssh_host' => "{$name}.test",
        'wireguard_ip' => $name === 'one' ? '10.44.0.2' : '10.44.0.3',
    ]);
}

function route_migration_instance(
    App\Models\App $app,
    App\Models\Node $node,
    string $name,
): App\Models\AppInstance {
    return App\Models\AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/srv/{$name}",
        'status' => AppInstanceState::Active,
    ]);
}
