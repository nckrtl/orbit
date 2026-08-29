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
        DB::statement('DROP TRIGGER IF EXISTS nodes_sync_legacy_user_after_insert');
        DB::statement('DROP TRIGGER IF EXISTS nodes_sync_legacy_user_after_user_update');
        DB::statement('DROP TRIGGER IF EXISTS nodes_validate_legacy_user_before_insert');
        DB::statement('DROP TRIGGER IF EXISTS nodes_validate_legacy_user_before_update');

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
        DB::statement('DROP TRIGGER IF EXISTS nodes_sync_legacy_user_after_insert');
        DB::statement('DROP TRIGGER IF EXISTS nodes_sync_legacy_user_after_user_update');
        DB::statement('DROP TRIGGER IF EXISTS nodes_validate_legacy_user_before_insert');
        DB::statement('DROP TRIGGER IF EXISTS nodes_validate_legacy_user_before_update');
        Schema::table('nodes', static function (Blueprint $table): void {
            $table->string('ssh_user')->default('root')->after('public_ssh_port');
        });
        DB::statement('UPDATE nodes SET ssh_user = user');
        Schema::table('nodes', static function (Blueprint $table): void {
            $table->string('user')->nullable()->default(null)->change();
        });
        DB::statement(
            "CREATE TRIGGER nodes_sync_user_after_insert AFTER INSERT ON nodes WHEN NEW.user IS NULL OR NEW.user = '' BEGIN UPDATE nodes SET user = CASE WHEN NEW.ssh_user IS NULL OR NEW.ssh_user = '' OR NEW.ssh_user = 'root' THEN 'orbit' ELSE NEW.ssh_user END WHERE id = NEW.id; END",
        );
        DB::statement(
            "CREATE TRIGGER nodes_validate_legacy_user_before_insert BEFORE INSERT ON nodes WHEN NEW.ssh_user IS NOT NULL AND NEW.ssh_user <> '' AND (NEW.ssh_user GLOB '*[^a-z0-9_-]*' OR length(CAST(NEW.ssh_user AS BLOB)) > 32 OR instr(NEW.ssh_user, char(0)) > 0 OR substr(NEW.ssh_user, 1, 1) NOT GLOB '[a-z_]') BEGIN SELECT RAISE(ABORT, 'Invalid legacy node user.'); END",
        );
        DB::statement(
            "CREATE TRIGGER nodes_validate_legacy_user_before_update BEFORE UPDATE OF ssh_user ON nodes WHEN NEW.ssh_user IS NOT NULL AND NEW.ssh_user <> '' AND (NEW.ssh_user GLOB '*[^a-z0-9_-]*' OR length(CAST(NEW.ssh_user AS BLOB)) > 32 OR instr(NEW.ssh_user, char(0)) > 0 OR substr(NEW.ssh_user, 1, 1) NOT GLOB '[a-z_]') BEGIN SELECT RAISE(ABORT, 'Invalid legacy node user.'); END",
        );
        DB::statement(
            "CREATE TRIGGER nodes_sync_user_after_legacy_update AFTER UPDATE OF ssh_user ON nodes WHEN NEW.user IS NOT NEW.ssh_user BEGIN UPDATE nodes SET user = CASE WHEN NEW.ssh_user IS NULL OR NEW.ssh_user = '' OR NEW.ssh_user = 'root' THEN 'orbit' ELSE NEW.ssh_user END WHERE id = NEW.id; END",
        );
        DB::statement(
            "CREATE TRIGGER nodes_sync_legacy_user_after_insert AFTER INSERT ON nodes WHEN NEW.user IS NOT NULL AND NEW.user <> '' AND NEW.ssh_user IS NOT NEW.user AND NOT (NEW.user = 'orbit' AND NEW.ssh_user = 'root') BEGIN UPDATE nodes SET ssh_user = NEW.user WHERE id = NEW.id; END",
        );
        DB::statement(
            "CREATE TRIGGER nodes_sync_legacy_user_after_user_update AFTER UPDATE OF user ON nodes WHEN NEW.user IS NOT OLD.user AND NEW.ssh_user IS NOT NEW.user AND NOT (NEW.user = 'orbit' AND NEW.ssh_user = 'root') BEGIN UPDATE nodes SET ssh_user = NEW.user WHERE id = NEW.id; END",
        );
    }
};
