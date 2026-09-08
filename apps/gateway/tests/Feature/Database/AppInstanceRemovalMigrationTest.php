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
use App\Models\AppInstanceRemovalMember;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('preserves populated AppInstance and Route state while adding empty removal storage', function (): void {
    $migration = orb179_removal_migration();
    $migration->down();
    $timestamp = '2026-09-08 12:00:00';
    $app = OrbitApp::query()->create([
        'name' => 'Upgrade',
        'slug' => 'upgrade',
        'repository_url' => 'https://example.test/acme/upgrade.git',
        'repository_identity' => 'example.test/acme/upgrade',
        'default_branch' => 'main',
        'root' => 'public',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $node = Node::query()->create([
        'name' => 'upgrade-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'wireguard_ip' => '10.44.0.80',
        'tld' => 'test',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

    foreach (['reserved', 'checkout_prepared', 'source_resolved'] as $position => $status) {
        AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => $status,
            'environment' => 'development',
            'source_layout' => 'checkout',
            'checkout_path' => "/srv/orbit/apps/upgrade/{$status}",
            'root' => $position === 0 ? null : 'site/public',
            'branch' => $status,
            'branch_override' => $position === 2 ? 'release' : null,
            'migration_required' => false,
            'starting_commit' => str_repeat((string) ($position + 1), 40),
            'status' => $status,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    [$active, $route] = orb179_removal_fixture('upgrade-active', $app, $node, $timestamp);
    $before = orb179_control_plane_rows();

    $migration->up();

    expect(orb179_control_plane_rows())
        ->toBe($before)
        ->and($active->refresh()->status)
        ->toBe(AppInstanceState::Active)
        ->and($active->app->repository_identity)
        ->toBe('example.test/acme/upgrade')
        ->and($route->refresh()->targets()->pluck('app_instance_id')->all())
        ->toBe([$active->id])
        ->and(Schema::hasTable('app_instance_removals'))
        ->toBeTrue()
        ->and(Schema::hasTable('app_instance_removal_members'))
        ->toBeTrue()
        ->and(DB::table('app_instance_removals')->count())
        ->toBe(0)
        ->and(DB::table('app_instance_removal_members')->count())
        ->toBe(0)
        ->and(Schema::hasTable('active_app_prod_nodes'))
        ->toBeFalse();
});

it('rejects a removal operation without its initial step', function (): void {
    [$instance] = orb179_removal_fixture('missing-step');

    expect(fn () => DB::table('app_instance_removals')->insert([
        'id' => (string) Str::uuid(),
        'requested_app_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => false,
        'inventory_digest' => str_repeat('d', 64),
        'total' => 1,
        'status' => 'removing',
        'current_step' => null,
        'failed_step' => null,
        'error_code' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))
        ->toThrow(QueryException::class);
});

it('records immutable requested identity, force choice, and ordered member inventory', function (): void {
    [$first, $firstRoute] = orb179_removal_fixture('first');
    [$second, $secondRoute] = orb179_removal_fixture('second');
    $removal = orb179_removal_operation($first, total: 2, force: true);
    $secondMember = $removal->members()->create(orb179_removal_member($second, $secondRoute, 1));

    expect(fn () => $second->update(['status' => AppInstanceState::Removing]))
        ->toThrow(QueryException::class);

    $second->refresh();
    $firstMember = $removal->members()->create(orb179_removal_member($first, $firstRoute, 0));
    $duplicate = orb179_removal_operation($first);

    expect($removal->refresh()->force)
        ->toBeTrue()
        ->and($removal->status)
        ->toBe(AppInstanceRemovalStatus::Removing)
        ->and($removal->current_step)
        ->toBe(AppInstanceRemovalStep::SourcePreparation)
        ->and($removal->members->pluck('id')->all())
        ->toBe([$firstMember->id, $secondMember->id])
        ->and($first->refresh()->removalMember?->id)
        ->toBe($firstMember->id)
        ->and($firstMember->removal->id)
        ->toBe($removal->id)
        ->and(fn () => $removal->update(['requested_name' => 'changed']))
        ->toThrow(QueryException::class)
        ->and(fn () => $removal->update(['force' => false]))
        ->toThrow(QueryException::class)
        ->and(fn () => $firstMember->update(['position' => 1]))
        ->toThrow(QueryException::class)
        ->and(fn () => $firstMember->update(['source_identity' => '1:101']))
        ->toThrow(QueryException::class)
        ->and(fn () => $firstMember->update(['source_commit' => str_repeat('f', 40)]))
        ->toThrow(QueryException::class)
        ->and(fn () => $duplicate->members()->create(orb179_removal_member($first, $firstRoute, 0)))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('app_instance_removals')
            ->where('id', $removal->id)
            ->update([
                'status' => 'queued',
            ]))
        ->toThrow(QueryException::class);

    $removal
        ->refresh()
        ->update([
            'status' => AppInstanceRemovalStatus::Failed,
            'current_step' => AppInstanceRemovalStep::SourcePreparation,
            'failed_step' => AppInstanceRemovalStep::SourcePreparation,
            'error_code' => 'instance.source_preparation_failed',
        ]);

    expect($removal->refresh()->status)
        ->toBe(AppInstanceRemovalStatus::Failed)
        ->and(fn () => $removal->update(['status' => AppInstanceRemovalStatus::Removing]))
        ->toThrow(QueryException::class);

    $removal
        ->refresh()
        ->update([
            'status' => AppInstanceRemovalStatus::Removing,
            'failed_step' => null,
            'error_code' => null,
        ]);
    $first->update(['status' => AppInstanceState::Removing]);
    $second->update(['status' => AppInstanceState::Removing]);

    expect($first->refresh()->status)
        ->toBe(AppInstanceState::Removing)
        ->and($second->refresh()->status)
        ->toBe(AppInstanceState::Removing);
});

it('backfills historical source commits and preserves distinct observed evidence', function (): void {
    $migration = orb182_source_commit_migration();
    $migration->down();
    [$historical, $historicalRoute] = orb179_removal_fixture('source-backfill');
    $historicalRemoval = orb179_removal_operation($historical);
    $historicalAttributes = orb179_removal_member($historical, $historicalRoute, 0);
    unset($historicalAttributes['source_commit']);
    $historicalMember = $historicalRemoval->members()->create($historicalAttributes);

    expect(Schema::hasColumn('app_instance_removal_members', 'source_commit'))->toBeFalse();

    $migration->up();

    expect($historicalMember->refresh()->source_commit)
        ->toBe($historical->starting_commit)
        ->and(Schema::hasColumn('app_instance_removal_members', 'source_commit'))
        ->toBeTrue()
        ->and(fn () => $historicalMember->update(['source_commit' => str_repeat('f', 40)]))
        ->toThrow(QueryException::class);

    [$current, $currentRoute] = orb179_removal_fixture('source-current');
    $currentRemoval = orb179_removal_operation($current);
    $currentAttributes = orb179_removal_member($current, $currentRoute, 0);
    unset($currentAttributes['source_commit']);

    expect(fn () => $currentRemoval->members()->create($currentAttributes))
        ->toThrow(QueryException::class);

    $currentAttributes['source_commit'] = str_repeat('b', 40);
    $currentMember = $currentRemoval->members()->create($currentAttributes);

    expect($currentMember->source_commit)
        ->toBe(str_repeat('b', 40))
        ->and($currentMember->starting_commit)
        ->toBe(str_repeat('a', 40))
        ->and(fn () => $migration->down())
        ->toThrow(
            RuntimeException::class,
            "Cannot roll back distinct AppInstance removal source commits: {$currentMember->id}",
        );
});

it('orders member checkpoints and completes only after every row deletion', function (): void {
    [$instance, $route] = orb179_removal_fixture('checkpoints');
    $removal = orb179_removal_operation($instance);
    $member = $removal->members()->create(orb179_removal_member($instance, $route, 0));
    $instance->update(['status' => AppInstanceState::Removing]);

    expect(fn () => $member->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']))
        ->toThrow(QueryException::class)
        ->and(fn () => $removal->update([
            'status' => AppInstanceRemovalStatus::Completed,
            'current_step' => null,
        ]))
        ->toThrow(QueryException::class);

    $member->refresh();
    $removal->refresh();
    $member->update(['source_prepared_at' => now()]);
    $member->refresh();

    expect(fn () => $member->update([
        'source_finalized_at' => now(),
        'finalization_receipt' => str_repeat('c', 64),
    ]))
        ->toThrow(QueryException::class);

    $member->refresh();
    $route->targets()->delete();
    $member->update(['route_cleared_at' => now(), 'route_outcome' => 'retained']);

    expect(fn () => $member->update(['runtime_cleaned_at' => now()]))
        ->toThrow(QueryException::class);

    $member->refresh();
    $member->update([
        'source_finalized_at' => now(),
        'finalization_receipt' => str_repeat('c', 64),
    ]);
    $member->update(['runtime_cleaned_at' => now()]);

    expect(fn () => $member->update(['row_deleted_at' => now()]))
        ->toThrow(QueryException::class);

    $member->refresh();
    $instance->delete();
    $member->update(['row_deleted_at' => now()]);
    $removal->update([
        'status' => AppInstanceRemovalStatus::Completed,
        'current_step' => null,
    ]);

    expect($removal->refresh()->status)
        ->toBe(AppInstanceRemovalStatus::Completed)
        ->and($removal->current_step)
        ->toBeNull()
        ->and($member->refresh()->row_deleted_at)
        ->not
        ->toBeNull()
        ->and(fn () => $member->update(['runtime_cleaned_at' => now()->addSecond()]))
        ->toThrow(QueryException::class)
        ->and(fn () => $member->delete())
        ->toThrow(QueryException::class)
        ->and(fn () => $removal->delete())
        ->toThrow(QueryException::class);
});

it('refuses rollback before discarding unfinished removal evidence', function (): void {
    [$instance] = orb179_removal_fixture('unfinished');
    orb179_removal_operation($instance);
    $migration = orb179_removal_migration();
    $schema = orb179_removal_schema();

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot roll back while AppInstance removal evidence exists.')
        ->and(orb179_removal_schema())
        ->toBe($schema);
});

it('refuses rollback before discarding completed removal evidence', function (): void {
    [$instance, $route] = orb179_removal_fixture('completed');
    $removal = orb179_removal_operation($instance);
    $member = $removal->members()->create(orb179_removal_member($instance, $route, 0));
    $instance->update(['status' => AppInstanceState::Removing]);
    $member->update(['source_prepared_at' => now()]);
    $route->targets()->delete();
    $member->update(['route_cleared_at' => now(), 'route_outcome' => 'retained']);
    $member->update([
        'source_finalized_at' => now(),
        'finalization_receipt' => str_repeat('c', 64),
    ]);
    $member->update(['runtime_cleaned_at' => now()]);
    $instance->delete();
    $member->update(['row_deleted_at' => now()]);
    $removal->update(['status' => AppInstanceRemovalStatus::Completed, 'current_step' => null]);
    $migration = orb179_removal_migration();
    $schema = orb179_removal_schema();

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot roll back while AppInstance removal evidence exists.')
        ->and(orb179_removal_schema())
        ->toBe($schema);
});

it('refuses rollback before narrowing an incompatible removing lifecycle', function (): void {
    [$instance] = orb179_removal_fixture('incompatible');
    DB::statement('DROP TRIGGER app_instances_removal_status_update');
    $instance->update(['status' => AppInstanceState::Removing]);
    $migration = orb179_removal_migration();
    $schema = orb179_removal_schema();

    expect(fn () => $migration->down())
        ->toThrow(
            RuntimeException::class,
            "Cannot roll back while AppInstances are removing: {$instance->id}",
        )
        ->and(orb179_removal_schema())
        ->toBe($schema);

    $instance->update(['status' => AppInstanceState::Active]);
    $migration->down();
    $migration->up();
});

it('restores the prior schema when no removal evidence or removing lifecycle exists', function (): void {
    $migration = orb179_removal_migration();
    $migration->down();
    $priorSchema = orb179_route_schema();

    expect(Schema::hasTable('app_instance_removals'))
        ->toBeFalse()
        ->and(Schema::hasTable('app_instance_removal_members'))
        ->toBeFalse();

    $migration->up();
    $migration->down();

    expect(orb179_route_schema())
        ->toBe($priorSchema)
        ->and(fn () => DB::table('app_instances')->insert([
            'app_id' => 1,
            'node_id' => 1,
            'name' => 'invalid',
            'checkout_path' => '/srv/invalid',
            'status' => 'removing',
            'created_at' => now(),
            'updated_at' => now(),
        ]))
        ->toThrow(QueryException::class);

    $migration->up();
});

function orb179_removal_migration(): object
{
    return require
        base_path(
            'database/migrations/2026_09_08_000000_persist_app_instance_removal_inventory.php',
        );
}

function orb182_source_commit_migration(): object
{
    return require
        base_path(
            'database/migrations/2026_09_08_204126_add_source_commit_to_app_instance_removal_members.php',
        );
}

/** @return array{AppInstance, Route} */
function orb179_removal_fixture(
    string $suffix,
    ?OrbitApp $app = null,
    ?Node $node = null,
    ?string $timestamp = null,
): array {
    $app ??= OrbitApp::query()->create([
        'name' => "Removal {$suffix}",
        'slug' => "removal-{$suffix}",
        'repository_url' => "https://example.test/acme/{$suffix}.git",
        'repository_identity' => "example.test/acme/{$suffix}",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node ??= Node::query()->create([
        'name' => "removal-{$suffix}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$suffix}.example.test",
        'wireguard_ip' => '10.44.'.((mb_strlen($suffix) % 200) + 1).'.10',
        'tld' => null,
    ]);
    $node->roles()->firstOrCreate(
        ['role' => RoleName::AppDev],
        ['status' => LifecycleStatus::Active],
    );
    $attributes = [
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $suffix,
        'environment' => 'development',
        'source_layout' => 'checkout',
        'checkout_path' => "/srv/orbit/apps/{$app->slug}/{$suffix}",
        'root' => null,
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::SourceResolved,
    ];

    if ($timestamp !== null) {
        $attributes['created_at'] = $timestamp;
        $attributes['updated_at'] = $timestamp;
    }

    $instance = AppInstance::query()->create($attributes);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $node->id,
        'hostname' => "{$suffix}.{$app->slug}.test",
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
        ...($timestamp === null ? [] : ['created_at' => $timestamp, 'updated_at' => $timestamp]),
    ]);
    $route
        ->targets()
        ->create([
            'app_instance_id' => $instance->id,
            'position' => 0,
            ...($timestamp === null ? [] : ['created_at' => $timestamp, 'updated_at' => $timestamp]),
        ]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);

    return [$instance->load('app'), $route];
}

function orb179_removal_operation(
    AppInstance $instance,
    int $total = 1,
    bool $force = false,
): AppInstanceRemoval {
    return AppInstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_app_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => $force,
        'inventory_digest' => str_repeat('d', 64),
        'total' => $total,
        'status' => AppInstanceRemovalStatus::Removing,
        'current_step' => AppInstanceRemovalStep::SourcePreparation,
    ]);
}

/** @return array<string, mixed> */
function orb179_removal_member(AppInstance $instance, Route $route, int $position): array
{
    return [
        'position' => $position,
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
        'source_commit' => $instance->starting_commit,
        'common_repository_path' => $instance->checkout_path,
        'source_identity' => "1:{$instance->id}",
        'linked_worktree_paths' => [$instance->checkout_path],
        'source_digest' => hash('sha256', "source:{$instance->id}"),
    ];
}

/** @return array<string, list<array<string, mixed>>> */
function orb179_control_plane_rows(): array
{
    $rows = [];

    foreach (['apps', 'app_instances', 'routes', 'route_targets'] as $table) {
        $rows[$table] = DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    return $rows;
}

/** @return list<array<string, mixed>> */
function orb179_removal_schema(): array
{
    return collect(DB::select(<<<'SQL'
        SELECT type, name, tbl_name, sql
        FROM sqlite_master
        WHERE type IN ('table', 'index', 'trigger')
            AND (
                tbl_name IN (
                    'app_instances',
                    'app_instance_removals',
                    'app_instance_removal_members',
                    'routes',
                    'route_targets'
                )
                OR name LIKE 'app_instance_removal%'
            )
        ORDER BY type, name
        SQL))
        ->map(static fn (object $entry): array => (array) $entry)
        ->all();
}

/** @return list<array<string, mixed>> */
function orb179_route_schema(): array
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
