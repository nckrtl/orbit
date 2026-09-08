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
