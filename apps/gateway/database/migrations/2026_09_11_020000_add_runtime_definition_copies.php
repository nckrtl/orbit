<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->timestamp('runtime_definitions_captured_at')->nullable();
        });

        Schema::table('processes', static function (Blueprint $table): void {
            $table->uuid('source_definition_id')->nullable();
            $table->unique(
                ['owner_type', 'owner_id', 'source_definition_id'],
                'processes_owner_definition_unique',
            );
        });

        Schema::table('schedules', static function (Blueprint $table): void {
            $table->uuid('source_definition_id')->nullable();
            $table->unique(
                ['target_type', 'target_id', 'source_definition_id'],
                'schedules_target_definition_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('schedules', static function (Blueprint $table): void {
            $table->dropUnique('schedules_target_definition_unique');
            $table->dropColumn('source_definition_id');
        });

        Schema::table('processes', static function (Blueprint $table): void {
            $table->dropUnique('processes_owner_definition_unique');
            $table->dropColumn('source_definition_id');
        });

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->dropColumn('runtime_definitions_captured_at');
        });
    }
};
