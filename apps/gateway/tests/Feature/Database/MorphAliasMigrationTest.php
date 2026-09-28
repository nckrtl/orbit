<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('rewrites legacy Instance morph types so alias only identities remain', function (): void {
    $original = DB::getDefaultConnection();
    config(['database.connections.morph_alias_migration' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('morph_alias_migration');

    try {
        foreach ([
            'processes' => 'owner_type',
            'schedules' => 'target_type',
            'task_groups' => 'taskable_type',
            'activity_log' => null,
        ] as $table => $typeColumn) {
            Schema::create($table, function (Blueprint $blueprint) use ($typeColumn): void {
                $blueprint->id();
                if ($typeColumn !== null) {
                    $blueprint->string($typeColumn);
                } else {
                    $blueprint->string('subject_type')->nullable();
                    $blueprint->string('causer_type')->nullable();
                }
            });
        }

        foreach ([
            ['processes', 'owner_type'],
            ['schedules', 'target_type'],
            ['task_groups', 'taskable_type'],
        ] as [$table, $column]) {
            DB::table($table)->insert([
                [$column => 'App\\Models\\AppInstance'],
                [$column => 'instance'],
            ]);
        }

        foreach (range(1, 1005) as $id) {
            DB::table('activity_log')->insert([
                'subject_type' => 'App\\Models\\AppInstance',
                'causer_type' => $id === 1 ? 'App\\Models\\AppInstance' : 'instance',
            ]);
        }

        run_legacy_schema_migration(require database_path('migrations/2026_09_30_090000_rewrite_app_instance_morph_types_to_alias.php'), 'up');

        foreach ([
            ['processes', 'owner_type'],
            ['schedules', 'target_type'],
            ['task_groups', 'taskable_type'],
            ['activity_log', 'subject_type'],
            ['activity_log', 'causer_type'],
        ] as [$table, $column]) {
            expect(DB::table($table)->where($column, 'App\\Models\\AppInstance')->exists())->toBeFalse();
            expect(DB::table($table)->where($column, 'instance')->count())->toBeGreaterThan(0);
        }
        expect(DB::table('activity_log')->where('subject_type', 'instance')->count())->toBe(1005)
            ->and(DB::table('activity_log')->where('causer_type', 'instance')->count())->toBe(1005);
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('morph_alias_migration');
    }
});
