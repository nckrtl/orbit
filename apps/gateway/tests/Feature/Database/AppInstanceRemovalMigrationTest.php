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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('keeps the accepted removal identity and ordered checkpoint evidence immutable', function (): void {
    [$instance, $route] = orb124_removal_db_fixture();
    $removal = orb124_removal_db_operation($instance);
    $member = $removal
        ->members()
        ->create([
            'position' => 0,
            'app_instance_id' => $instance->id,
            'app_id' => $instance->app_id,
            'node_id' => $instance->node_id,
            'route_id' => $route->id,
            'name' => $instance->name,
            'environment' => 'development',
            'source_layout' => 'checkout',
            'repository_identity' => $instance->app->repository_identity,
            'checkout_path' => $instance->checkout_path,
            'root' => '/srv/orbit/apps/acme',
            'branch' => 'main',
            'starting_commit' => str_repeat('a', 40),
            'common_repository_path' => $instance->checkout_path,
            'linked_worktree_paths' => [$instance->checkout_path],
            'source_digest' => str_repeat('b', 64),
        ]);
    $instance->update(['status' => AppInstanceState::Removing]);

    expect(fn () => $removal->update(['force' => true]))
        ->toThrow(QueryException::class)
        ->and(fn () => $member->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']))
        ->toThrow(QueryException::class)
        ->and(fn () => $member->update(['linked_worktree_paths' => []]))
        ->toThrow(QueryException::class);

    $member->refresh();
    $removal->refresh();
    $member->update(['source_prepared_at' => now()]);
    $route->targets()->delete();
    $member->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']);
    $member->update([
        'source_finalized_at' => now(),
        'finalization_receipt' => str_repeat('c', 64),
    ]);
    $member->update(['runtime_cleaned_at' => now()]);
    $instance->delete();
    $member->update(['row_deleted_at' => now()]);
    $removal->update([
        'status' => AppInstanceRemovalStatus::Completed,
        'current_step' => null,
    ]);

    expect($member
        ->refresh()
        ->only([
            'app_instance_id',
            'route_id',
            'route_outcome',
            'finalization_receipt',
        ]))
        ->toBe([
            'app_instance_id' => $instance->id,
            'route_id' => $route->id,
            'route_outcome' => 'deleted',
            'finalization_receipt' => str_repeat('c', 64),
        ])
        ->and(fn () => $member->delete())
        ->toThrow(QueryException::class)
        ->and(fn () => $removal->delete())
        ->toThrow(QueryException::class)
        ->and(fn () => $removal->update(['status' => AppInstanceRemovalStatus::Removing]))
        ->toThrow(QueryException::class);
});

it('refuses rollback while removal is unfinished without changing its schema', function (): void {
    [$instance] = orb124_removal_db_fixture();
    orb124_removal_db_operation($instance);
    $migration = require
        base_path(
            'database/migrations/2026_09_07_200000_make_app_instance_removal_retry_safe.php',
        );

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot roll back while an AppInstance removal is unfinished.')
        ->and(Schema::hasTable('app_instance_removals'))
        ->toBeTrue()
        ->and(Schema::hasColumn('app_instances', 'status'))
        ->toBeTrue();
});

it('owns a clean rollback and forward reapplication', function (): void {
    $migration = require
        base_path(
            'database/migrations/2026_09_07_200000_make_app_instance_removal_retry_safe.php',
        );

    $migration->down();

    expect(Schema::hasTable('app_instance_removals'))
        ->toBeFalse()
        ->and(Schema::hasTable('app_instance_removal_members'))
        ->toBeFalse()
        ->and(Schema::hasTable('active_app_prod_nodes'))
        ->toBeFalse();

    $migration->up();

    expect(Schema::hasTable('app_instance_removals'))
        ->toBeTrue()
        ->and(Schema::hasTable('app_instance_removal_members'))
        ->toBeTrue()
        ->and(Schema::hasTable('active_app_prod_nodes'))
        ->toBeTrue();
});

/** @return array{AppInstance, Route} */
function orb124_removal_db_fixture(): array
{
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.20',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'main',
        'checkout_path' => '/srv/orbit/apps/acme/main',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $node->id,
        'hostname' => 'acme.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);

    return [$instance->load('app'), $route];
}

function orb124_removal_db_operation(AppInstance $instance): AppInstanceRemoval
{
    return AppInstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_app_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => false,
        'inventory_digest' => str_repeat('d', 64),
        'total' => 1,
        'status' => AppInstanceRemovalStatus::Removing,
        'current_step' => AppInstanceRemovalStep::SourcePreparation,
    ]);
}
