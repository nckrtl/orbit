<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    app_era_instance_leftover_migration()->down();
}

function drop_app_era_instance_leftovers_for_migration_test(): void
{
    if (Schema::hasColumn('app_instances', 'migration_required')) {
        DB::table('app_instances')->update(['migration_required' => false]);
    }

    if (Schema::hasColumn('app_instances', 'registration_migration_recovery')) {
        DB::table('app_instances')->update(['registration_migration_recovery' => null]);
    }

    if (Schema::hasColumn('apps', 'defaults')) {
        DB::table('apps')->update(['defaults' => null]);
    }

    app_era_instance_leftover_migration()->up();
}

function roll_back_app_instance_environment_for_migration_test(): void
{
    restore_app_era_instance_leftovers_for_migration_test();
    app_instance_environment_migration()->down();
}

function restore_app_instance_environment_schema_for_migration_test(): void
{
    if (! Schema::hasColumn('app_instances', 'environment')
        || ! Schema::hasTable('node_roles')) {
        return;
    }

    DB::table('app_instances')
        ->whereNotIn('environment', ['development', 'production'])
        ->update(['environment' => 'development']);

    foreach (DB::table('app_instances')->select(['node_id', 'environment'])->distinct()->get() as $instance) {
        DB::table('node_roles')->updateOrInsert(
            [
                'node_id' => $instance->node_id,
                'role' => $instance->environment === 'production' ? 'app-prod' : 'app-dev',
            ],
            ['status' => 'active'],
        );
    }

    app_instance_environment_migration()->up();
    drop_app_era_instance_leftovers_for_migration_test();
}
