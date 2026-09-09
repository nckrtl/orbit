<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function production_route_target_set_migration(): Illuminate\Database\Migrations\Migration
{
    return require
        base_path(
            'database/migrations/2026_09_08_200000_enable_production_route_target_sets.php',
        );
}

function route_hostname_change_migration(): Illuminate\Database\Migrations\Migration
{
    return require
        base_path(
            'database/migrations/2026_09_09_070000_add_hostname_change_state_to_routes_table.php',
        );
}

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
    $duplicateNode = route_migration_instance($app, $nodeOne, 'duplicate', 'development');
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

it('stores only complete and directionally valid active development hostname changes', function (): void {
    $route = route_migration_hostname_change_route('validity');
    $operation = route_migration_hostname_change_attributes('validity-next.example.test');

    foreach ([
        ['hostname_change_previous' => $route->hostname],
        array_diff_key($operation, ['hostname_change_direction' => true]),
        array_diff_key($operation, ['hostname_change_step' => true]),
        [...$operation, 'hostname_change_direction' => 'sideways'],
        [...$operation, 'hostname_change_step' => 'unknown'],
        [...$operation, 'hostname_change_direction' => 'forward', 'hostname_change_step' => 'rollback-dns'],
        [...$operation, 'hostname_change_direction' => 'rollback', 'hostname_change_step' => 'workload-caddy'],
    ] as $invalid) {
        expect(fn () => DB::table('routes')->where('id', $route->id)->update($invalid))
            ->toThrow(QueryException::class);
    }

    expect(fn () => DB::table('routes')->where('id', $route->id)->update($operation))
        ->not
        ->toThrow(QueryException::class)
        ->and($route->refresh()->hostname_change_step?->value)
        ->toBe('reserved');
});

it('requires paired failure evidence during an active hostname change', function (): void {
    $route = route_migration_hostname_change_route('failure-pair');
    DB::table('routes')
        ->where('id', $route->id)
        ->update(
            route_migration_hostname_change_attributes('failure-pair-next.example.test'),
        );

    expect(fn () => DB::table('routes')->where('id', $route->id)->update(['failed_step' => 'workload-caddy']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('routes')->where('id', $route->id)->update(['error_code' => 'route.failed']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('routes')
            ->where('id', $route->id)
            ->update([
                'failed_step' => 'workload-caddy',
                'error_code' => 'route.failed',
            ]))
        ->not->toThrow(QueryException::class);
});

it('limits active hostname change state to one eligible development target', function (): void {
    $production = route_migration_hostname_change_route('production-operation', environment: 'production');
    $unknown = route_migration_hostname_change_route('unknown-operation', sourceIsLaravel: null);

    foreach ([$production, $unknown] as $route) {
        expect(fn () => DB::table('routes')
            ->where('id', $route->id)
            ->update(
                route_migration_hostname_change_attributes("{$route->id}-next.example.test", $route->hostname),
            ))
            ->toThrow(QueryException::class);
    }
});

it('keeps canonical and candidate hostname ownership exclusive across Routes', function (): void {
    $first = route_migration_hostname_change_route('first-owner');
    $second = route_migration_hostname_change_route('second-owner');

    expect(fn () => DB::table('routes')
        ->where('id', $first->id)
        ->update(
            route_migration_hostname_change_attributes($second->hostname, $first->hostname),
        ))
        ->toThrow(QueryException::class);

    DB::table('routes')
        ->where('id', $first->id)
        ->update(
            route_migration_hostname_change_attributes('candidate-owner.example.test', $first->hostname),
        );

    expect(fn () => Route::query()->create([
        'app_id' => $second->app_id,
        'node_id' => $second->node_id,
        'hostname' => 'candidate-owner.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]))
        ->toThrow(QueryException::class);
});

it('refuses hostname change rollback before discarding unfinished recovery evidence', function (): void {
    $route = route_migration_hostname_change_route('rollback-evidence');
    DB::table('routes')
        ->where('id', $route->id)
        ->update([
            ...route_migration_hostname_change_attributes(
                'rollback-evidence-next.example.test',
                $route->hostname,
            ),
            'failed_step' => 'workload-caddy',
            'error_code' => 'route.test_failure',
        ]);
    $evidenceBefore = $route->refresh()->getAttributes();
    $schemaBefore = route_migration_hostname_change_schema();

    expect(fn () => route_hostname_change_migration()->down())
        ->toThrow(RuntimeException::class, "operations are unfinished: {$route->id}");

    expect(route_migration_hostname_change_schema())
        ->toBe($schemaBefore)
        ->and($route->refresh()->getAttributes())
        ->toBe($evidenceBefore)
        ->and(Schema::hasColumns('routes', [
            'hostname_change_previous',
            'hostname_change_target',
            'hostname_change_direction',
            'hostname_change_step',
        ]))
        ->toBeTrue();
});

it('enforces multi-target storage with compatible Cluster-scoped production rows', function (): void {
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
    $oneNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $twoNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
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

    expect($route->targets()->pluck('position')->all())
        ->toBe([0, 1])
        ->and(fn () => $route->update(['status' => RouteStatus::Active]))
        ->not
        ->toThrow(QueryException::class)
        ->and(Schema::hasTable('active_app_prod_nodes'))
        ->toBeTrue();
    $one->update(['status' => AppInstanceState::SourceResolved]);
    $two->update(['status' => AppInstanceState::SourceResolved]);
    $route->targets()->delete();

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

it('preserves a compatible production target set across the forward migration', function (): void {
    $migration = production_route_target_set_migration();
    $migration->down();
    [$route, $one, $two] = route_migration_production_set('upgrade');
    $rowsBefore = route_migration_target_rows($route);

    $migration->up();
    $route->update(['status' => RouteStatus::Active]);

    expect(route_migration_target_rows($route))
        ->toBe($rowsBefore)
        ->and($route->refresh()->status)
        ->toBe(RouteStatus::Active)
        ->and($route->targets()->orderBy('position')->pluck('app_instance_id')->all())
        ->toBe([$one->id, $two->id]);
});

it('refuses an incompatible forward migration before changing schema or target membership', function (): void {
    $migration = production_route_target_set_migration();
    $migration->down();
    [$route] = route_migration_production_set('incompatible', environment: 'development');
    $schemaBefore = route_migration_target_schema();
    $rowsBefore = route_migration_target_rows($route);

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, "incompatible Routes: {$route->id}");
    expect(route_migration_target_schema())
        ->toBe($schemaBefore)
        ->and(route_migration_target_rows($route))
        ->toBe($rowsBefore)
        ->and(Schema::hasTable('active_app_prod_nodes'))
        ->toBeFalse();
});

it('refuses rollback before discarding a compatible production target set', function (): void {
    $migration = production_route_target_set_migration();
    [$route] = route_migration_production_set('rollback');
    $route->update(['status' => RouteStatus::Active]);
    $schemaBefore = route_migration_target_schema();
    $rowsBefore = route_migration_target_rows($route);

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, "multiple targets: {$route->id}");
    expect(route_migration_target_schema())
        ->toBe($schemaBefore)
        ->and(route_migration_target_rows($route))
        ->toBe($rowsBefore)
        ->and(Schema::hasTable('active_app_prod_nodes'))
        ->toBeTrue();
});

function route_migration_node(string $name): App\Models\Node
{
    static $suffix = 20;

    $suffix++;

    return App\Models\Node::query()->create([
        'name' => $name,
        'status' => 'active',
        'public_ssh_host' => "{$name}.test",
        'wireguard_ip' => "10.44.0.{$suffix}",
    ]);
}

function route_migration_instance(
    App\Models\App $app,
    App\Models\Node $node,
    string $name,
    string $environment = 'production',
): App\Models\AppInstance {
    return App\Models\AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => $environment,
        'checkout_path' => "/srv/{$name}",
        'status' => AppInstanceState::Active,
    ]);
}

function route_migration_hostname_change_route(
    string $suffix,
    string $environment = 'development',
    ?bool $sourceIsLaravel = false,
): Route {
    $app = App\Models\App::query()->create([
        'name' => "Hostname {$suffix}",
        'slug' => "hostname-{$suffix}",
        'repository_url' => "https://example.test/hostname-{$suffix}.git",
    ]);
    $node = route_migration_node("hostname-{$suffix}");
    $instance = App\Models\AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $suffix,
        'environment' => $environment,
        'checkout_path' => "/srv/{$suffix}",
        'source_is_laravel' => $sourceIsLaravel,
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => "{$suffix}.example.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}

/** @return array<string, string> */
function route_migration_hostname_change_attributes(string $target, ?string $previous = null): array
{
    return [
        'hostname_change_previous' => $previous ?? str_replace('-next', '', $target),
        'hostname_change_target' => $target,
        'hostname_change_direction' => 'forward',
        'hostname_change_step' => 'reserved',
    ];
}

/** @return array{Route, App\Models\AppInstance, App\Models\AppInstance} */
function route_migration_production_set(string $suffix, string $environment = 'production'): array
{
    $app = App\Models\App::query()->create([
        'name' => "Set {$suffix}",
        'slug' => "set-{$suffix}",
        'repository_url' => "https://example.test/set-{$suffix}.git",
    ]);
    $cluster = App\Models\Cluster::query()->create(['name' => "set-{$suffix}", 'state' => 'active']);
    $oneNode = route_migration_node("{$suffix}-one");
    $twoNode = route_migration_node("{$suffix}-two");
    $oneNode->update(['cluster_id' => $cluster->id]);
    $twoNode->update(['cluster_id' => $cluster->id]);
    $oneNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $twoNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $one = route_migration_instance($app, $oneNode, "{$suffix}-one");
    $two = route_migration_instance($app, $twoNode, "{$suffix}-two");
    $one->update(['environment' => $environment]);
    $two->update(['environment' => $environment]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'hostname' => "{$suffix}.example.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $one->id, 'position' => 0]);
    $route->targets()->create(['app_instance_id' => $two->id, 'position' => 1]);

    return [$route, $one, $two];
}

/** @return list<array<string, mixed>> */
function route_migration_target_rows(Route $route): array
{
    return DB::table('route_targets')
        ->where('route_id', $route->id)
        ->orderBy('position')
        ->get()
        ->map(static fn (object $row): array => (array) $row)
        ->all();
}

/** @return list<array<string, mixed>> */
function route_migration_target_schema(): array
{
    return collect(DB::select(<<<'SQL'
        SELECT type, name, tbl_name, sql
        FROM sqlite_master
        WHERE type IN ('table', 'index', 'trigger')
            AND tbl_name IN ('nodes', 'node_roles', 'routes', 'route_targets', 'active_app_prod_nodes')
        ORDER BY type, name
        SQL))
        ->map(static fn (object $entry): array => (array) $entry)
        ->all();
}

/** @return list<array<string, mixed>> */
function route_migration_hostname_change_schema(): array
{
    return collect(DB::select(<<<'SQL'
        SELECT type, name, tbl_name, sql
        FROM sqlite_master
        WHERE tbl_name = 'routes'
        ORDER BY type, name
        SQL))
        ->map(static fn (object $entry): array => (array) $entry)
        ->all();
}
