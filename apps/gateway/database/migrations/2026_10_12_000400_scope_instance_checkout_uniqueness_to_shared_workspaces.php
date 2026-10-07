<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX instances_node_id_checkout_path_unique');
        DB::statement('CREATE UNIQUE INDEX instances_node_id_checkout_path_unique ON instances (node_id, checkout_path) WHERE task_sandbox_id IS NULL');
    }

    public function down(): void
    {
        if (DB::table('instances')->whereNotNull('task_sandbox_id')->exists()) {
            throw new RuntimeException('Remove sandbox workspaces before restoring shared checkout uniqueness.');
        }
        DB::statement('DROP INDEX instances_node_id_checkout_path_unique');
        DB::statement('CREATE UNIQUE INDEX instances_node_id_checkout_path_unique ON instances (node_id, checkout_path)');
    }
};
