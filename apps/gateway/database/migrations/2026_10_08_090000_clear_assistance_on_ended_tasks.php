<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks that already ended can still ask for assistance. Clear that flag and keep the last reason.
 * A later save of a completed or cancelled task clears the flag again. The old flag is not recoverable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (['task_groups', 'tasks'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status') || ! Schema::hasColumn($table, 'assistance_requested')) {
                continue;
            }

            DB::table($table)
                ->whereIn('status', ['completed', 'cancelled'])
                ->where('assistance_requested', true)
                ->update([
                    'assistance_requested' => false,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // The cleared flags are not stored anywhere else, so a rollback cannot restore them.
    }
};
