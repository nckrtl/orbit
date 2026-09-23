<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR 0116: a TaskGroup selects its implementer and reviewer drivers separately. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_groups', static function (Blueprint $table): void {
            $table->string('implementer_agent_driver')->default('t3');
            $table->string('reviewer_agent_driver')->default('t3');
        });
        DB::table('task_groups')->update([
            'implementer_agent_driver' => DB::raw('agent_driver'),
            'reviewer_agent_driver' => DB::raw('agent_driver'),
        ]);
        Schema::table('task_groups', static function (Blueprint $table): void {
            $table->dropColumn('agent_driver');
        });
    }

    public function down(): void
    {
        if (DB::table('task_groups')->whereColumn('implementer_agent_driver', '!=', 'reviewer_agent_driver')->exists()) {
            throw new RuntimeException('A task group uses different implementer and reviewer drivers, which one agent_driver column cannot hold. Restore a database backup or apply a forward migration.');
        }
        Schema::table('task_groups', static function (Blueprint $table): void {
            $table->string('agent_driver')->default('t3');
        });
        DB::table('task_groups')->update(['agent_driver' => DB::raw('implementer_agent_driver')]);
        Schema::table('task_groups', static function (Blueprint $table): void {
            $table->dropColumn(['implementer_agent_driver', 'reviewer_agent_driver']);
        });
    }
};
