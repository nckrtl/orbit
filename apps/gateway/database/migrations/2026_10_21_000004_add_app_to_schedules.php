<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Instance Schedules and Schedule definitions name their app, like Processes. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['schedules', 'schedule_definitions'] as $name) {
            Schema::table($name, static function (Blueprint $table): void {
                $table->string('app', 63)->nullable();
            });
        }
        DB::table('schedules')->whereIn('target_type', ['instance', 'App\\Models\\Instance'])->update(['app' => 'web']);
        DB::table('schedule_definitions')->update(['app' => 'web']);
    }

    public function down(): void
    {
        foreach (['schedules', 'schedule_definitions'] as $name) {
            Schema::table($name, static function (Blueprint $table): void {
                $table->dropColumn('app');
            });
        }
    }
};
