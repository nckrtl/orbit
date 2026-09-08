<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('refuses unsupported legacy source ownership before changing schema or rows', function (): void {
    $removalMigration = app_instance_identity_removal_migration();
    $migration = app_instance_identity_migration();
    $removalMigration->down();

    try {
        $migration->down();
        $ids = app_instance_identity_legacy_graph();
        DB::table('app_instances')
            ->where('id', $ids['instance'])
            ->update([
                'source_kind' => 'registered_worktree',
            ]);
        $schemaBefore = app_instance_identity_schema();
        $rowsBefore = app_instance_identity_rows();

        expect(fn () => $migration->up())
            ->toThrow(
                RuntimeException::class,
                "Cannot migrate unsupported AppInstance source ownership: {$ids['instance']}",
            );

        expect(app_instance_identity_schema())
            ->toBe($schemaBefore)
            ->and(app_instance_identity_rows())
            ->toBe($rowsBefore);
    } finally {
        DB::table('app_instances')
            ->where('source_kind', 'registered_worktree')
            ->update(['source_kind' => 'managed_clone']);
        $migration->up();
        $removalMigration->up();
    }
});

it('migrates stable source identity without changing legacy rows or relationships', function (): void {
    $removalMigration = app_instance_identity_removal_migration();
    $migration = app_instance_identity_migration();
    $removalMigration->down();

    try {
        $migration->down();
        $ids = app_instance_identity_legacy_graph();
        $legacy = app_instance_identity_rows();
        $legacySchema = app_instance_identity_schema();

        $migration->up();

        $migrated = app_instance_identity_rows();
        $sourceLayout = collect(DB::select('PRAGMA table_info(app_instances)'))
            ->firstWhere('name', 'source_layout');
        expect(Schema::hasColumns('apps', ['default_branch']))
            ->toBeTrue()
            ->and(Schema::hasColumn('apps', 'main_branch'))
            ->toBeFalse()
            ->and(Schema::hasColumns(
                'app_instances',
                ['source_layout', 'branch_override', 'migration_required'],
            ))
            ->toBeTrue()
            ->and(Schema::hasColumn('app_instances', 'source_kind'))
            ->toBeFalse()
            ->and($sourceLayout->dflt_value)
            ->toBe("'checkout'")
            ->and($migrated['apps'][0]['default_branch'])
            ->toBe($legacy['apps'][0]['main_branch'])
            ->and($migrated['app_instances'][0]['source_layout'])
            ->toBe('checkout')
            ->and($migrated['app_instances'][0]['branch_override'])
            ->toBeNull()
            ->and($migrated['app_instances'][0]['migration_required'])
            ->toBe(1)
            ->and($migrated['app_instances'][1]['source_layout'])
            ->toBe('checkout')
            ->and($migrated['app_instances'][1]['branch_override'])
            ->toBeNull()
            ->and($migrated['app_instances'][1]['migration_required'])
            ->toBe(0)
            ->and($migrated['routes'])
            ->toBe($legacy['routes'])
            ->and($migrated['route_targets'])
            ->toBe($legacy['route_targets']);

        $unchangedApp = $migrated['apps'][0];
        unset($unchangedApp['default_branch']);
        $legacyApp = $legacy['apps'][0];
        unset($legacyApp['main_branch']);
        $unchangedInstances = array_map(static function (array $instance): array {
            unset($instance['source_layout'], $instance['branch_override'], $instance['migration_required']);

            return $instance;
        }, $migrated['app_instances']);
        $legacyInstances = array_map(static function (array $instance): array {
            unset($instance['source_kind']);

            return $instance;
        }, $legacy['app_instances']);

        expect($unchangedApp)
            ->toBe($legacyApp)
            ->and($unchangedInstances)
            ->toBe($legacyInstances)
            ->and(fn () => DB::table('app_instances')
                ->where('id', $ids['instance'])
                ->update([
                    'source_layout' => 'managed_clone',
                ]))
            ->toThrow(QueryException::class);

        $migration->down();

        expect(app_instance_identity_rows())
            ->toBe($legacy)
            ->and(app_instance_identity_schema())
            ->toBe($legacySchema);
    } finally {
        if (! Schema::hasColumn('app_instances', 'source_layout')) {
            $migration->up();
        }

        $removalMigration->up();
    }
});

function app_instance_identity_migration(): object
{
    return require
        base_path(
            'database/migrations/2026_09_07_000000_migrate_app_and_app_instance_source_identity.php',
        );
}

function app_instance_identity_removal_migration(): object
{
    return require
        base_path(
            'database/migrations/2026_09_08_000000_persist_app_instance_removal_inventory.php',
        );
}

/** @return array{app: int, instance: int, route: int, target: int} */
function app_instance_identity_legacy_graph(): array
{
    $timestamp = '2026-09-07 06:00:00';
    $node = Node::query()->create([
        'name' => 'legacy-app-dev',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.50',
    ]);
    $app = DB::table('apps')->insertGetId([
        'name' => 'Legacy',
        'slug' => 'legacy',
        'repository_url' => 'https://github.com/acme/legacy.git',
        'repository_identity' => 'github.com/acme/legacy',
        'main_branch' => 'main',
        'root' => 'public',
        'defaults' => '{"safe":"value"}',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $instance = DB::table('app_instances')->insertGetId([
        'app_id' => $app,
        'node_id' => $node->id,
        'name' => 'main',
        'environment' => 'development',
        'source_kind' => 'managed_clone',
        'checkout_path' => '/srv/orbit/apps/legacy/main',
        'root' => null,
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'provisioning_step' => 'active',
        'failed_step' => null,
        'error_code' => null,
        'status' => 'reserved',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    DB::table('app_instances')->insert([
        'app_id' => $app,
        'node_id' => $node->id,
        'name' => 'preview',
        'environment' => 'development',
        'source_kind' => 'managed_clone',
        'checkout_path' => '/srv/orbit/apps/legacy/preview',
        'root' => 'site/public',
        'branch' => 'release',
        'starting_commit' => str_repeat('b', 40),
        'selected_php_version' => '8.4',
        'provisioning_step' => 'source-resolved',
        'failed_step' => null,
        'error_code' => null,
        'status' => 'source_resolved',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $route = DB::table('routes')->insertGetId([
        'app_id' => $app,
        'node_id' => $node->id,
        'cluster_id' => null,
        'generation_basis_node_id' => $node->id,
        'hostname' => 'legacy.test',
        'provenance' => 'generated',
        'publication' => 'private',
        'status' => 'pending',
        'failed_step' => null,
        'error_code' => null,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $target = DB::table('route_targets')->insertGetId([
        'route_id' => $route,
        'app_instance_id' => $instance,
        'position' => 0,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    DB::table('routes')->where('id', $route)->update(['status' => 'active']);
    DB::table('app_instances')->where('id', $instance)->update(['status' => 'active']);

    return ['app' => $app, 'instance' => $instance, 'route' => $route, 'target' => $target];
}

/** @return array<string, list<array<string, mixed>>> */
function app_instance_identity_rows(): array
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

/** @return array{objects: list<array<string, mixed>>, columns: list<array<string, mixed>>, foreign_keys: list<array<string, mixed>>} */
function app_instance_identity_schema(): array
{
    $objects = collect(DB::select(<<<'SQL'
        SELECT type, name, tbl_name, sql
        FROM sqlite_master
        WHERE type IN ('table', 'index', 'trigger')
            AND tbl_name IN ('apps', 'app_instances', 'routes', 'route_targets')
        ORDER BY type, name
        SQL))
        ->reject(static fn (object $entry): bool => $entry->type === 'table' && $entry->name === 'app_instances')
        ->map(static fn (object $entry): array => (array) $entry)
        ->all();
    $columns = collect(DB::select('PRAGMA table_info(app_instances)'))
        ->map(static function (object $column): array {
            $attributes = (array) $column;
            unset($attributes['cid']);

            return $attributes;
        })
        ->sortBy('name')
        ->values()
        ->all();
    $foreignKeys = collect(DB::select('PRAGMA foreign_key_list(app_instances)'))
        ->map(static fn (object $foreignKey): array => (array) $foreignKey)
        ->sortBy(['table', 'from'])
        ->values()
        ->all();

    return [
        'objects' => $objects,
        'columns' => $columns,
        'foreign_keys' => $foreignKeys,
    ];
}
