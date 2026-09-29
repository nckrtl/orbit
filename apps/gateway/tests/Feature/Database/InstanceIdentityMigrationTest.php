<?php

declare(strict_types=1);

use App\Models\Instance;
use App\Models\Node;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => roll_back_app_instance_environment_for_migration_test());
afterEach(function (): void {
    if (Schema::hasColumn('instances', 'migration_required')) {
        DB::table('instances')->update(['migration_required' => false]);
    }

    if (Schema::hasColumn('instances', 'registration_migration_recovery')) {
        DB::table('instances')->update(['registration_migration_recovery' => null]);
    }

    restore_app_instance_environment_schema_for_migration_test();
});

it('refuses unsupported legacy source ownership before changing schema or rows', function (): void {
    $removalMigration = instance_identity_removal_migration();
    $migration = instance_identity_migration();
    run_legacy_schema_migration($removalMigration, 'down');

    try {
        run_legacy_schema_migration($migration, 'down');
        $ids = instance_identity_legacy_graph();
        DB::table('instances')
            ->where('id', $ids['instance'])
            ->update([
                'source_kind' => 'registered_worktree',
            ]);
        $schemaBefore = instance_identity_schema();
        $rowsBefore = instance_identity_rows();

        expect(fn () => run_legacy_schema_migration($migration, 'up'))
            ->toThrow(
                RuntimeException::class,
                "Cannot migrate unsupported AppInstance source ownership: {$ids['instance']}",
            );

        expect(instance_identity_schema())
            ->toBe($schemaBefore)
            ->and(instance_identity_rows())
            ->toBe($rowsBefore);
    } finally {
        DB::table('instances')
            ->where('source_kind', 'registered_worktree')
            ->update(['source_kind' => 'managed_clone']);
        run_legacy_schema_migration($migration, 'up');
        run_legacy_schema_migration($removalMigration, 'up');
    }
});

it('migrates stable source identity without changing legacy rows or relationships', function (): void {
    $removalMigration = instance_identity_removal_migration();
    $migration = instance_identity_migration();
    run_legacy_schema_migration($removalMigration, 'down');

    try {
        run_legacy_schema_migration($migration, 'down');
        $ids = instance_identity_legacy_graph();
        $legacy = instance_identity_rows();
        $legacySchema = instance_identity_schema();

        run_legacy_schema_migration($migration, 'up');

        $migrated = instance_identity_rows();
        $sourceLayout = collect(DB::select('PRAGMA table_info(instances)'))
            ->firstWhere('name', 'source_layout');
        expect(Schema::hasColumns('projects', ['default_branch']))
            ->toBeTrue()
            ->and(Schema::hasColumn('projects', 'main_branch'))
            ->toBeFalse()
            ->and(Schema::hasColumns(
                'instances',
                ['source_layout', 'branch_override', 'migration_required'],
            ))
            ->toBeTrue()
            ->and(Schema::hasColumn('instances', 'source_kind'))
            ->toBeFalse()
            ->and($sourceLayout->dflt_value)
            ->toBe("'checkout'")
            ->and($migrated['projects'][0]['default_branch'])
            ->toBe($legacy['projects'][0]['main_branch'])
            ->and($migrated['instances'][0]['source_layout'])
            ->toBe('checkout')
            ->and($migrated['instances'][0]['branch_override'])
            ->toBeNull()
            ->and($migrated['instances'][0]['migration_required'])
            ->toBe(1)
            ->and($migrated['instances'][1]['source_layout'])
            ->toBe('checkout')
            ->and($migrated['instances'][1]['branch_override'])
            ->toBeNull()
            ->and($migrated['instances'][1]['migration_required'])
            ->toBe(0)
            ->and($migrated['routes'])
            ->toBe($legacy['routes'])
            ->and($migrated['route_targets'])
            ->toBe($legacy['route_targets']);

        $unchangedApp = $migrated['projects'][0];
        unset($unchangedApp['default_branch']);
        $legacyApp = $legacy['projects'][0];
        unset($legacyApp['main_branch']);
        $unchangedInstances = array_map(static function (array $instance): array {
            unset($instance['source_layout'], $instance['branch_override'], $instance['migration_required']);

            return $instance;
        }, $migrated['instances']);
        $legacyInstances = array_map(static function (array $instance): array {
            unset($instance['source_kind']);

            return $instance;
        }, $legacy['instances']);

        expect($unchangedApp)
            ->toBe($legacyApp)
            ->and($unchangedInstances)
            ->toBe($legacyInstances)
            ->and(fn () => DB::table('instances')
                ->where('id', $ids['instance'])
                ->update([
                    'source_layout' => 'managed_clone',
                ]))
            ->toThrow(QueryException::class);

        run_legacy_schema_migration($migration, 'down');

        expect(instance_identity_rows())
            ->toBe($legacy)
            ->and(instance_identity_schema())
            ->toBe($legacySchema);
    } finally {
        if (! Schema::hasColumn('instances', 'source_layout')) {
            run_legacy_schema_migration($migration, 'up');
        }

        run_legacy_schema_migration($removalMigration, 'up');
    }
});

function instance_identity_migration(): object
{
    return require base_path(
        'database/migrations/2026_09_07_000000_migrate_app_and_app_instance_source_identity.php',
    );
}

function instance_identity_removal_migration(): object
{
    return app_instance_removal_migration_boundary();
}

/** @return array{project: int, instance: int, route: int, target: int} */
function instance_identity_legacy_graph(): array
{
    $timestamp = '2026-09-07 06:00:00';
    $node = Node::query()->create([
        'name' => 'legacy-app-dev',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.50',
    ]);
    $project = DB::table('projects')->insertGetId([
        'name' => 'Legacy',
        'code' => 'LEG',
        'slug' => 'legacy',
        'repository_url' => 'https://github.com/acme/legacy.git',
        'repository_identity' => 'github.com/acme/legacy',
        'main_branch' => 'main',
        'root' => 'public',
        'defaults' => '{"safe":"value"}',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $instance = DB::table('instances')->insertGetId([
        'project_id' => $project,
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
    DB::table('instances')->insert([
        'project_id' => $project,
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
        'project_id' => $project,
        'node_id' => $node->id,
        'cluster_id' => null,
        'generation_basis_node_id' => $node->id,
        'domain' => 'legacy.test',
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
        'instance_id' => $instance,
        'position' => 0,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    DB::table('routes')->where('id', $route)->update(['status' => 'active']);
    DB::table('instances')->where('id', $instance)->update(['status' => 'active']);

    return ['project' => $project, 'instance' => $instance, 'route' => $route, 'target' => $target];
}

/** @return array<string, list<array<string, mixed>>> */
function instance_identity_rows(): array
{
    $rows = [];

    foreach (['projects', 'instances', 'routes', 'route_targets'] as $table) {
        $rows[$table] = DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    return $rows;
}

/** @return array{objects: list<array<string, mixed>>, columns: list<array<string, mixed>>, foreign_keys: list<array<string, mixed>>} */
function instance_identity_schema(): array
{
    $objects = collect(DB::select(<<<'SQL'
        SELECT type, name, tbl_name, sql
        FROM sqlite_master
        WHERE type IN ('table', 'index', 'trigger')
            AND tbl_name IN ('projects', 'instances', 'routes', 'route_targets')
        ORDER BY type, name
        SQL))
        ->reject(static fn (object $entry): bool => $entry->type === 'table' && $entry->name === 'instances')
        ->map(static fn (object $entry): array => (array) $entry)
        ->all();
    $columns = collect(DB::select('PRAGMA table_info(instances)'))
        ->map(static function (object $column): array {
            $attributes = (array) $column;
            unset($attributes['cid']);

            return $attributes;
        })
        ->sortBy('name')
        ->values()
        ->all();
    $foreignKeys = collect(DB::select('PRAGMA foreign_key_list(instances)'))
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
