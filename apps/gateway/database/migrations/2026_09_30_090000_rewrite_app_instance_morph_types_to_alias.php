<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string LegacyType = 'App\\Models\\AppInstance';

    public function up(): void
    {
        foreach ([
            'processes' => 'owner_type',
            'schedules' => 'target_type',
            'task_groups' => 'taskable_type',
        ] as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::table($table)->where($column, self::LegacyType)->update([$column => 'instance']);
            }
        }

        if (Schema::hasTable('activity_log')) {
            foreach (['subject_type', 'causer_type'] as $column) {
                if (Schema::hasColumn('activity_log', $column)) {
                    DB::table('activity_log')->where($column, self::LegacyType)
                        ->chunkById(1000, static function ($activities) use ($column): void {
                            DB::table('activity_log')->whereIn('id', $activities->pluck('id'))
                                ->update([$column => 'instance']);
                        });
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Morph alias migration cannot distinguish legacy class names from aliases written after the upgrade.');
    }
};
