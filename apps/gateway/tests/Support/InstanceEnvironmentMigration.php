<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function owned_interrupted_creation_removal_migration(): object
{
    return require base_path('database/migrations/2026_10_10_000001_allow_owned_interrupted_creation_removal.php');
}

function app_instance_environment_migration(): object
{
    return require base_path('database/migrations/2026_09_30_100000_drop_environment_from_app_instances.php');
}

function app_era_instance_leftover_migration(): object
{
    return require base_path('database/migrations/2026_10_03_000000_drop_app_era_instance_leftovers.php');
}

function restore_app_era_instance_leftovers_for_migration_test(): void
{
    run_legacy_schema_migration(app_era_instance_leftover_migration(), 'down');
}

function drop_app_era_instance_leftovers_for_migration_test(): void
{
    if (Schema::hasColumn('projects', 'defaults')) {
        DB::table('projects')->update(['defaults' => null]);
    }

    run_legacy_schema_migration(app_era_instance_leftover_migration(), 'up');
}

function roll_back_app_instance_environment_for_migration_test(): void
{
    (require base_path('database/migrations/2026_10_12_000000_allow_source_resolved_workspace_route_removal.php'))->down();
    owned_interrupted_creation_removal_migration()->down();
    (require base_path('database/migrations/2026_10_10_000000_add_instance_source_prepare_id.php'))->down();
    (require base_path('database/migrations/2026_10_09_000000_allow_pre_activation_instance_removal.php'))->down();
    restore_app_era_instance_leftovers_for_migration_test();
    run_legacy_schema_migration(app_instance_environment_migration(), 'down');
}

function restore_app_instance_environment_schema_for_migration_test(): void
{
    if (! Schema::hasColumn('instances', 'environment')
        || ! Schema::hasTable('node_roles')) {
        return;
    }

    DB::table('instances')
        ->whereNotIn('environment', ['development', 'production'])
        ->update(['environment' => 'development']);

    foreach (DB::table('instances')->select(['node_id', 'environment'])->distinct()->get() as $instance) {
        DB::table('node_roles')->updateOrInsert(
            [
                'node_id' => $instance->node_id,
                'role' => $instance->environment === 'production' ? 'app-prod' : 'app-dev',
            ],
            ['status' => 'active'],
        );
    }

    run_legacy_schema_migration(app_instance_environment_migration(), 'up');
    drop_app_era_instance_leftovers_for_migration_test();
}
