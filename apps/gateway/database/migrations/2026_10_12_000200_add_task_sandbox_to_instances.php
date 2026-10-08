<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add the nullable reference in place; rebuilding instances invalidates cross-table triggers.
        DB::statement('ALTER TABLE instances ADD COLUMN task_sandbox_id VARCHAR REFERENCES task_sandboxes(id) ON DELETE RESTRICT');
        Schema::table('instances', fn (Blueprint $table) => $table->unique('task_sandbox_id'));
    }

    public function down(): void
    {
        if (DB::table('instances')->whereNotNull('task_sandbox_id')->exists()) {
            throw new RuntimeException('Remove sandbox workspaces before rolling back their ownership.');
        }
        Schema::table('instances', fn (Blueprint $table) => $table->dropUnique(['task_sandbox_id']));
        DB::statement('ALTER TABLE instances DROP COLUMN task_sandbox_id');
    }
};
