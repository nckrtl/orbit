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
        Schema::table('task_sandboxes', function (Blueprint $table): void {
            $table->json('enrollment')->nullable();
            $table->timestamp('enrolled_at')->nullable();
        });
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Preserve cross-table triggers by avoiding SQLite's table rebuild.
            DB::statement('ALTER TABLE nodes ADD COLUMN compute_sandbox_id varchar(36) NULL REFERENCES task_sandboxes(id) ON DELETE RESTRICT');
            Schema::table('nodes', fn (Blueprint $table) => $table->unique('compute_sandbox_id'));

            return;
        }
        Schema::table('nodes', function (Blueprint $table): void {
            $table->uuid('compute_sandbox_id')->nullable()->unique();
            $table->foreign('compute_sandbox_id')->references('id')->on('task_sandboxes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('nodes')->whereNotNull('compute_sandbox_id')->exists()
            || DB::table('task_sandboxes')->whereNotNull('enrollment')->where('state', '!=', 'destroyed')->exists()) {
            throw new RuntimeException('Remove enrolled sandbox resources before rolling back their ownership.');
        }
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('nodes', fn (Blueprint $table) => $table->dropUnique(['compute_sandbox_id']));
            DB::statement('ALTER TABLE nodes DROP COLUMN compute_sandbox_id');
        } else {
            Schema::table('nodes', function (Blueprint $table): void {
                $table->dropForeign(['compute_sandbox_id']);
                $table->dropUnique(['compute_sandbox_id']);
                $table->dropColumn('compute_sandbox_id');
            });
        }
        Schema::table('task_sandboxes', function (Blueprint $table): void {
            $table->dropColumn(['enrollment', 'enrolled_at']);
        });
    }
};
