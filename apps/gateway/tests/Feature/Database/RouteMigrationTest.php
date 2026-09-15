<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function production_route_target_set_migration(): Migration
{
    return require base_path(
        'database/migrations/2026_09_08_200000_enable_production_route_target_sets.php',
    );
}

it('stores exclusive Route scope, immutable provenance, basis, and pending lifecycle', function (): void {
    expect(Schema::hasColumns('routes', [
        'app_id',
        'node_id',
        'cluster_id',
        'generation_basis_node_id',
        'domain',
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
        'domain' => 'acme.test',
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
        'domain' => 'explicit.test',
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

it('keeps a persisted Route domain immutable', function (): void {
    $route = route_migration_replacement_route('immutable');

    expect(fn () => DB::table('routes')->where('id', $route->id)->update(['domain' => 'other.example.test']))
        ->toThrow(QueryException::class)
        ->and($route->refresh()->domain)
        ->toBe('immutable.example.test');
});

it('allows a linked replacement pair to share the same AppInstance targets', function (): void {
    [$current, $instance] = route_migration_replacement_pair('shared-target');
    $replacement = Route::query()->create([
        'app_id' => $current->app_id,
        'node_id' => $current->node_id,
        'domain' => 'shared-target-next.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $current->id,
        'replacement_step' => RouteReplacementStep::Reserved,
    ]);

    $replacement->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $current->update(['replaced_by_route_id' => $replacement->id]);

    expect($current->targets()->pluck('app_instance_id')->all())
        ->toBe([$instance->id])
        ->and($replacement->targets()->pluck('app_instance_id')->all())
        ->toBe([$instance->id]);
});

it('refuses a third unrelated Route targeting the same AppInstance', function (): void {
    [$current, $instance] = route_migration_replacement_pair('exclusive-target');
    $replacement = Route::query()->create([
        'app_id' => $current->app_id,
        'node_id' => $current->node_id,
        'domain' => 'exclusive-target-next.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $current->id,
        'replacement_step' => RouteReplacementStep::Reserved,
    ]);
    $replacement->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $unrelated = Route::query()->create([
        'app_id' => $current->app_id,
        'node_id' => $current->node_id,
        'domain' => 'exclusive-target-other.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);

    expect(fn () => $unrelated->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]))
        ->toThrow(QueryException::class)
        ->and($unrelated->targets()->count())
        ->toBe(0);
});

it('enforces unique Route domains', function (): void {
    $first = route_migration_replacement_route('unique-owner');

    expect(fn () => Route::query()->create([
        'app_id' => $first->app_id,
        'node_id' => $first->node_id,
        'domain' => $first->domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]))->toThrow(QueryException::class);
});

it('accepts pending active activating retiring and failed Route statuses', function (): void {
    $pending = route_migration_replacement_route('status-pending', activate: false);
    $active = route_migration_replacement_route('status-active');
    $activating = route_migration_replacement_route('status-activating');
    $retiring = route_migration_replacement_route('status-retiring');
    $failed = route_migration_replacement_route('status-failed');

    expect($pending->status)
        ->toBe(RouteStatus::Pending)
        ->and(fn () => $active->update(['status' => RouteStatus::Active]))
        ->not->toThrow(QueryException::class)
        ->and(fn () => $activating->update(['status' => RouteStatus::Activating]))
        ->not->toThrow(QueryException::class)
        ->and(fn () => $retiring->update(['status' => RouteStatus::Retiring]))
        ->not->toThrow(QueryException::class)
        ->and(fn () => $failed->update([
            'status' => RouteStatus::Failed,
            'failed_step' => 'workload-caddy',
            'error_code' => 'route.domain_change_failed',
        ]))
        ->not->toThrow(QueryException::class)
        ->and(fn () => $active->update([
            'failed_step' => 'dns-publication',
            'error_code' => 'route.domain_change_failed',
        ]))
        ->toThrow(QueryException::class);

    $active->refresh();

    expect(fn () => $active->update([
        'replacement_step' => RouteReplacementStep::DnsPublished,
        'failed_step' => 'dns-publication',
        'error_code' => 'route.domain_change_failed',
    ]))
        ->not->toThrow(QueryException::class)
        ->and(fn () => DB::table('routes')->insert([
            'app_id' => $pending->app_id,
            'node_id' => $pending->node_id,
            'domain' => 'status-invalid.example.test',
            'provenance' => RouteProvenance::Explicit->value,
            'publication' => RoutePublication::Private->value,
            'status' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]))
        ->toThrow(QueryException::class);
});

it('allows target-set failure evidence and empty explicit Cluster Routes', function (): void {
    $active = route_migration_replacement_route('target-set-evidence');
    $active->update(['target_set_step' => 'reserved']);

    expect(fn () => $active->update([
        'failed_step' => 'database-committed',
        'error_code' => 'route.target_set_failed',
    ]))
        ->not->toThrow(QueryException::class);

    $app = App\Models\App::query()->create([
        'name' => 'Vacated',
        'slug' => 'vacated',
        'repository_url' => 'https://example.test/vacated.git',
    ]);
    $cluster = Cluster::query()->create(['name' => 'vacated', 'state' => 'active']);
    $empty = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'domain' => 'vacated-empty.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);

    expect(fn () => $empty->update(['status' => RouteStatus::Active]))
        ->not->toThrow(QueryException::class)
        ->and($empty->refresh()->status)
        ->toBe(RouteStatus::Active);
});

it('enforces multi-target storage with compatible Cluster-scoped production rows', function (): void {
    $app = App\Models\App::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
    ]);
    $cluster = Cluster::query()->create(['name' => 'cluster', 'state' => 'active']);
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
        'domain' => 'explicit.test',
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
        'domain' => 'generated.test',
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

function route_migration_node(string $name): Node
{
    static $suffix = 20;

    $suffix++;

    return Node::query()->create([
        'name' => $name,
        'status' => 'active',
        'public_ssh_host' => "{$name}.test",
        'wireguard_ip' => "10.44.0.{$suffix}",
    ]);
}

function route_migration_instance(
    App\Models\App $app,
    Node $node,
    string $name,
    string $environment = 'production',
): AppInstance {
    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => $environment,
        'checkout_path' => "/srv/{$name}",
        'status' => AppInstanceState::Active,
    ]);
}

function route_migration_replacement_route(string $suffix, bool $activate = true): Route
{
    [$route] = route_migration_replacement_pair($suffix, $activate);

    return $route;
}

/** @return array{Route, AppInstance} */
function route_migration_replacement_pair(string $suffix, bool $activate = true): array
{
    $app = App\Models\App::query()->create([
        'name' => "Domain {$suffix}",
        'slug' => "domain-{$suffix}",
        'repository_url' => "https://example.test/domain-{$suffix}.git",
    ]);
    $node = route_migration_node("domain-{$suffix}");
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $suffix,
        'environment' => 'development',
        'checkout_path' => "/srv/{$suffix}",
        'source_is_laravel' => false,
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'domain' => "{$suffix}.example.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);

    if ($activate) {
        $route->update(['status' => RouteStatus::Active]);
    }

    return [$route->refresh(), $instance->refresh()];
}

/** @return array{Route, AppInstance, AppInstance} */
function route_migration_production_set(string $suffix, string $environment = 'production'): array
{
    $app = App\Models\App::query()->create([
        'name' => "Set {$suffix}",
        'slug' => "set-{$suffix}",
        'repository_url' => "https://example.test/set-{$suffix}.git",
    ]);
    $cluster = Cluster::query()->create(['name' => "set-{$suffix}", 'state' => 'active']);
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
        'domain' => "{$suffix}.example.test",
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
