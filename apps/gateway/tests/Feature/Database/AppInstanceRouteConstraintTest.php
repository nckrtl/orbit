<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceRemovalStatus;
use App\Domain\AppInstances\AppInstanceRemovalStep;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('reports every invalid active AppInstance before changing the upgrade schema or rows', function (): void {
    $migration = require
        base_path(
            'database/migrations/2026_09_05_000000_provision_development_app_instances.php',
        );
    $migration->down();

    $app = OrbitApp::query()->create([
        'name' => 'Preflight',
        'slug' => 'preflight',
        'repository_url' => 'https://example.test/preflight.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'preflight-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.60',
        'wireguard_ip' => '10.44.0.60',
        'tld' => 'test',
    ]);
    $missingRoute = app_instance_route_preflight_instance($app, $node, 'missing-route');
    $valid = app_instance_route_preflight_instance($app, $node, 'valid');
    $multipleRoutes = app_instance_route_preflight_instance($app, $node, 'multiple-routes');
    $validRoute = app_instance_route_preflight_route($app, $node, 'valid.test');
    $firstRoute = app_instance_route_preflight_route($app, $node, 'multiple-one.test');
    $secondRoute = app_instance_route_preflight_route($app, $node, 'multiple-two.test');
    $validRoute->targets()->create(['app_instance_id' => $valid->id, 'position' => 0]);
    $firstRoute->targets()->create(['app_instance_id' => $multipleRoutes->id, 'position' => 0]);
    $secondRoute->targets()->create(['app_instance_id' => $multipleRoutes->id, 'position' => 0]);
    $schemaBefore = app_instance_route_preflight_schema();
    $rowsBefore = app_instance_route_preflight_rows();

    expect(fn () => $migration->up())
        ->toThrow(
            RuntimeException::class,
            "Active AppInstances must have exactly one Route before upgrade: {$missingRoute->id}, {$multipleRoutes->id}",
        );

    expect(app_instance_route_preflight_schema())
        ->toBe($schemaBefore)
        ->and(app_instance_route_preflight_rows())
        ->toBe($rowsBefore);
});

it('permits activation only after one Route association exists', function (): void {
    [$instance, $route] = app_instance_route_constraint_fixture();

    expect(fn () => $instance->update(['status' => AppInstanceState::Active]))
        ->toThrow(QueryException::class);

    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $instance->update(['status' => AppInstanceState::Active]);

    expect($instance->refresh()->status)->toBe(AppInstanceState::Active);
});

it('enforces global AppInstance Route uniqueness at the database boundary', function (): void {
    [$instance, $first] = app_instance_route_constraint_fixture();
    $first->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $second = Route::query()->create([
        'app_id' => $instance->app_id,
        'node_id' => $instance->node_id,
        'hostname' => 'second.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);

    expect(fn () => $second->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]))
        ->toThrow(QueryException::class);
});

it('does not let association deletion strand an active AppInstance', function (): void {
    [$instance, $route] = app_instance_route_constraint_fixture();
    $target = $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);

    expect(fn () => $target->delete())->toThrow(QueryException::class);
});

it('allows only a recorded removing member to lose its Route after source preparation', function (): void {
    [$instance, $route] = app_instance_route_constraint_fixture();
    $target = $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);

    expect(fn () => $instance->update(['status' => AppInstanceState::Removing]))
        ->toThrow(QueryException::class);

    $removal = AppInstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_app_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => false,
        'inventory_digest' => str_repeat('d', 64),
        'total' => 1,
        'status' => AppInstanceRemovalStatus::Removing,
        'current_step' => AppInstanceRemovalStep::SourcePreparation,
    ]);
    $member = $removal
        ->members()
        ->create([
            'position' => 0,
            'app_instance_id' => $instance->id,
            'app_id' => $instance->app_id,
            'node_id' => $instance->node_id,
            'route_id' => $route->id,
            'name' => $instance->name,
            'environment' => $instance->environment,
            'source_layout' => $instance->source_layout,
            'repository_identity' => $instance->app->repository_identity,
            'checkout_path' => $instance->checkout_path,
            'root' => $instance->effectiveRoot(),
            'branch' => $instance->branch,
            'starting_commit' => $instance->starting_commit,
            'common_repository_path' => $instance->checkout_path,
            'source_identity' => "1:{$instance->id}",
            'linked_worktree_paths' => [$instance->checkout_path],
            'source_digest' => str_repeat('b', 64),
        ]);
    $instance->update(['status' => AppInstanceState::Removing]);

    expect(fn () => $target->delete())
        ->toThrow(QueryException::class);

    $member->update(['source_prepared_at' => now()]);
    $target->delete();

    expect($instance->refresh()->status)
        ->toBe(AppInstanceState::Removing)
        ->and($route->refresh()->targets)
        ->toHaveCount(0);
});

it('rejects a removing AppInstance inserted without a recorded operation', function (): void {
    [$instance] = app_instance_route_constraint_fixture();

    expect(fn () => AppInstance::query()->create([
        'app_id' => $instance->app_id,
        'node_id' => $instance->node_id,
        'name' => 'unrecorded',
        'checkout_path' => '/srv/orbit/apps/constraint/unrecorded',
        'status' => AppInstanceState::Removing,
    ]))
        ->toThrow(QueryException::class);
});

it('persists an ordered explicit production Route across distinct active app-prod Nodes', function (): void {
    [$app, $cluster, $one, $two] = production_route_constraint_fixture();
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'hostname' => 'shared.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $two->id, 'position' => 1]);
    $route->targets()->create(['app_instance_id' => $one->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $one->update(['status' => AppInstanceState::Active]);
    $two->update(['status' => AppInstanceState::Active]);

    expect($route->targets()->orderBy('position')->pluck('app_instance_id')->all())
        ->toBe([$one->id, $two->id]);
});

it('rejects gapped writes after production Route activation and permits atomic delete compaction', function (): void {
    [$app, $cluster, $one, $two] = production_route_constraint_fixture();
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'hostname' => 'active-order.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $first = $route->targets()->create(['app_instance_id' => $one->id, 'position' => 0]);
    $second = $route->targets()->create(['app_instance_id' => $two->id, 'position' => 1]);
    $route->update(['status' => RouteStatus::Active]);
    $threeNode = Node::query()->create([
        'name' => 'shared-three',
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => 'shared-three.test',
        'wireguard_ip' => '10.44.0.83',
    ]);
    $threeNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $three = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $threeNode->id,
        'name' => 'three',
        'environment' => 'production',
        'checkout_path' => '/srv/three',
        'status' => AppInstanceState::SourceResolved,
    ]);

    expect(fn () => $second->update(['position' => 2]))
        ->toThrow(QueryException::class)
        ->and(fn () => $route->targets()->create(['app_instance_id' => $three->id, 'position' => 3]))
        ->toThrow(QueryException::class)
        ->and($route->targets()->orderBy('position')->pluck('position')->all())
        ->toBe([0, 1]);

    $route->targets()->create(['app_instance_id' => $three->id, 'position' => 2]);
    DB::transaction(function () use ($first, $route): void {
        $first->delete();

        foreach ($route->targets()->orderBy('position')->orderBy('id')->get() as $position => $target) {
            $target->update(['position' => $position]);
        }
    });

    expect($route->targets()->orderBy('position')->pluck('position')->all())
        ->toBe([0, 1]);
});

it('prevents an existing shared production target set from drifting through related records', function (): void {
    [$app, $cluster, $one, $two] = production_route_constraint_fixture();
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'hostname' => 'stable.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $one->id, 'position' => 0]);
    $route->targets()->create(['app_instance_id' => $two->id, 'position' => 1]);
    $route->update(['status' => RouteStatus::Active]);

    expect(fn () => $one->node->update(['status' => LifecycleStatus::Failed]))
        ->toThrow(QueryException::class)
        ->and(fn () => $two
            ->node
            ->roles()
            ->where('role', RoleName::AppProd->value)
            ->update([
                'status' => LifecycleStatus::Failed->value,
            ]))
        ->toThrow(QueryException::class)
        ->and(fn () => $one->update(['environment' => 'development']))
        ->toThrow(QueryException::class);

    $other = Cluster::query()->create(['name' => 'drift', 'state' => 'active']);

    expect(fn () => $route->update(['cluster_id' => $other->id]))
        ->toThrow(QueryException::class)
        ->and($route->refresh()->cluster_id)
        ->toBe($cluster->id)
        ->and($route->targets()->count())
        ->toBe(2);
});

it('refuses to activate a production target set with a position gap', function (): void {
    [$app, $cluster, $one, $two] = production_route_constraint_fixture();
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'hostname' => 'gap.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $one->id, 'position' => 0]);
    $route->targets()->create(['app_instance_id' => $two->id, 'position' => 2]);

    expect(fn () => $route->update(['status' => RouteStatus::Active]))
        ->toThrow(QueryException::class)
        ->and($route->refresh()->status)
        ->toBe(RouteStatus::Pending);
});

it('keeps generated and development Routes single-target', function (string $kind): void {
    [$app, $cluster, $one, $two] = production_route_constraint_fixture(
        environment: $kind === 'development' ? 'development' : 'production',
        role: $kind === 'development' ? RoleName::AppDev : RoleName::AppProd,
    );
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'generation_basis_node_id' => $kind === 'generated' ? $one->node_id : null,
        'hostname' => "{$kind}.example.test",
        'provenance' => $kind === 'generated' ? RouteProvenance::Generated : RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $one->id, 'position' => 0]);

    expect(fn () => $route->targets()->create(['app_instance_id' => $two->id, 'position' => 1]))
        ->toThrow(QueryException::class)
        ->and($route->targets()->count())
        ->toBe(1);
})->with(['generated', 'development']);

it('refuses a shared production target on a wrong Cluster, inactive role, or duplicate Node', function (string $invalid): void {
    [$app, $cluster, $one, $two] = production_route_constraint_fixture();

    if ($invalid === 'cluster') {
        $other = Cluster::query()->create(['name' => 'other', 'state' => 'active']);
        $two->node->update(['cluster_id' => $other->id]);
    } elseif ($invalid === 'role') {
        $two
            ->node
            ->roles()
            ->where('role', RoleName::AppProd->value)
            ->update([
                'status' => LifecycleStatus::Provisioning->value,
            ]);
    } else {
        $two->update(['node_id' => $one->node_id]);
    }

    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'hostname' => "invalid-{$invalid}.example.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $one->id, 'position' => 0]);

    expect(fn () => $route->targets()->create(['app_instance_id' => $two->id, 'position' => 1]))
        ->toThrow(QueryException::class)
        ->and($route->targets()->count())
        ->toBe(1);
})->with(['cluster', 'role', 'duplicate Node']);

/** @return array{AppInstance, Route} */
function app_instance_route_constraint_fixture(): array
{
    $app = OrbitApp::query()->create([
        'name' => 'Constraint',
        'slug' => 'constraint',
        'repository_url' => 'https://example.test/constraint.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'constraint-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.50',
        'wireguard_ip' => '10.44.0.50',
        'tld' => 'test',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'main',
        'checkout_path' => '/srv/orbit/apps/constraint/main',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $node->id,
        'hostname' => 'constraint.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);

    return [$instance, $route];
}

/** @return array{OrbitApp, Cluster, AppInstance, AppInstance} */
function production_route_constraint_fixture(
    string $environment = 'production',
    RoleName $role = RoleName::AppProd,
): array {
    $app = OrbitApp::query()->create([
        'name' => 'Shared',
        'slug' => 'shared',
        'repository_url' => 'https://example.test/shared.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $cluster = Cluster::query()->create(['name' => 'shared', 'state' => 'active']);
    $instances = collect(['one', 'two'])->map(function (string $name) use (
        $app,
        $cluster,
        $environment,
        $role,
    ): AppInstance {
        $suffix = $name === 'one' ? '71' : '72';
        $node = Node::query()->create([
            'name' => "shared-{$name}",
            'cluster_id' => $cluster->id,
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => "192.0.2.{$suffix}",
            'wireguard_ip' => "10.44.0.{$suffix}",
        ]);
        $node->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active]);

        return AppInstance::query()
            ->create([
                'app_id' => $app->id,
                'node_id' => $node->id,
                'name' => $name,
                'environment' => $environment,
                'checkout_path' => "/var/www/shared/{$name}",
                'branch' => 'main',
                'starting_commit' => str_repeat('a', 40),
                'status' => AppInstanceState::SourceResolved,
            ])
            ->load('node');
    });

    return [$app, $cluster, $instances[0], $instances[1]];
}

function app_instance_route_preflight_instance(OrbitApp $app, Node $node, string $name): AppInstance
{
    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/srv/orbit/apps/preflight/{$name}",
        'branch' => $name,
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::Active,
    ]);
}

function app_instance_route_preflight_route(OrbitApp $app, Node $node, string $hostname): Route
{
    return Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => $hostname,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
}

/** @return list<array<string, mixed>> */
function app_instance_route_preflight_schema(): array
{
    return collect(DB::select(<<<'SQL'
        SELECT type, name, tbl_name, sql
        FROM sqlite_master
        WHERE type IN ('table', 'index', 'trigger')
            AND tbl_name IN ('app_instances', 'routes', 'route_targets')
        ORDER BY type, name
        SQL))
        ->map(static fn (object $entry): array => (array) $entry)
        ->all();
}

/** @return array<string, list<array<string, mixed>>> */
function app_instance_route_preflight_rows(): array
{
    return [
        'app_instances' => DB::table('app_instances')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all(),
        'routes' => DB::table('routes')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all(),
        'route_targets' => DB::table('route_targets')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all(),
    ];
}
