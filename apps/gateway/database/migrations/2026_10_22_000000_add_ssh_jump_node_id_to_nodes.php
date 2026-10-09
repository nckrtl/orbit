<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Node that public SSH to a Node goes through until that Node has an active role. Existing
 * Nodes have none. Removing the jump Node clears the reference, because enrolled Nodes no longer use it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Preserve cross-table triggers by avoiding SQLite's table rebuild.
            DB::statement('ALTER TABLE nodes ADD COLUMN ssh_jump_node_id integer NULL REFERENCES nodes(id) ON DELETE SET NULL');

            return;
        }

        Schema::table('nodes', static function (Blueprint $table): void {
            $table->foreignId('ssh_jump_node_id')->nullable()->constrained('nodes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE nodes DROP COLUMN ssh_jump_node_id');

            return;
        }

        Schema::table('nodes', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ssh_jump_node_id');
        });
    }
};
