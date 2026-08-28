<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS nodes_sync_user_after_insert');
        DB::statement('DROP TRIGGER IF EXISTS nodes_sync_user_after_legacy_update');

        if (DB::table('nodes')->whereNull('user')->orWhere('user', '')->exists()) {
            throw new RuntimeException('Cannot make nodes.user non-null while empty values exist.');
        }

        Schema::table('nodes', static function (Blueprint $table): void {
            $table->string('user')->default('orbit')->nullable(false)->change();
        });
        Schema::table('nodes', static function (Blueprint $table): void {
            $table->dropColumn('ssh_user');
        });
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS nodes_sync_user_after_insert');
        DB::statement('DROP TRIGGER IF EXISTS nodes_sync_user_after_legacy_update');
        Schema::table('nodes', static function (Blueprint $table): void {
            $table->string('ssh_user')->default('root')->after('public_ssh_port');
        });
        DB::statement('UPDATE nodes SET ssh_user = user');
        Schema::table('nodes', static function (Blueprint $table): void {
            $table->string('user')->nullable()->default(null)->change();
        });
        DB::statement(
            "CREATE TRIGGER nodes_sync_user_after_insert AFTER INSERT ON nodes WHEN NEW.user IS NULL OR NEW.user = '' BEGIN UPDATE nodes SET user = CASE WHEN NEW.ssh_user IS NULL OR NEW.ssh_user = '' THEN 'orbit' ELSE NEW.ssh_user END WHERE id = NEW.id; END",
        );
        DB::statement(
            "CREATE TRIGGER nodes_sync_user_after_legacy_update AFTER UPDATE OF ssh_user ON nodes BEGIN UPDATE nodes SET user = CASE WHEN NEW.ssh_user IS NULL OR NEW.ssh_user = '' THEN 'orbit' ELSE NEW.ssh_user END WHERE id = NEW.id; END",
        );
    }
};
