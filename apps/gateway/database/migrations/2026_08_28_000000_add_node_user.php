<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::table('nodes')
            ->whereNotNull('ssh_user')
            ->where('ssh_user', '<>', '')
            ->where(static function (Builder $query): void {
                $query
                    ->whereRaw("ssh_user GLOB '*[^a-z0-9_-]*'")
                    ->orWhereRaw('length(CAST(ssh_user AS BLOB)) > 32')
                    ->orWhereRaw('instr(ssh_user, char(0)) > 0')
                    ->orWhereRaw("substr(ssh_user, 1, 1) NOT GLOB '[a-z_]'");
            })
            ->exists()) {
            throw new RuntimeException('Invalid legacy node user.');
        }

        Schema::table('nodes', static function (Blueprint $table): void {
            $table->string('user')->nullable()->after('ssh_user');
        });

        DB::statement(
            "UPDATE nodes SET user = CASE WHEN ssh_user IS NULL OR ssh_user = '' THEN 'orbit' ELSE ssh_user END",
        );
        self::createTriggers();
    }

    public function down(): void
    {
        self::dropTriggers();
        Schema::table('nodes', static function (Blueprint $table): void {
            $table->dropColumn('user');
        });
    }

    private static function createTriggers(): void
    {
        DB::statement(
            "CREATE TRIGGER nodes_validate_legacy_user_before_insert BEFORE INSERT ON nodes WHEN NEW.ssh_user IS NOT NULL AND NEW.ssh_user <> '' AND (NEW.ssh_user GLOB '*[^a-z0-9_-]*' OR length(CAST(NEW.ssh_user AS BLOB)) > 32 OR instr(NEW.ssh_user, char(0)) > 0 OR substr(NEW.ssh_user, 1, 1) NOT GLOB '[a-z_]') BEGIN SELECT RAISE(ABORT, 'Invalid legacy node user.'); END",
        );
        DB::statement(
            "CREATE TRIGGER nodes_validate_legacy_user_before_update BEFORE UPDATE OF ssh_user ON nodes WHEN NEW.ssh_user IS NOT NULL AND NEW.ssh_user <> '' AND (NEW.ssh_user GLOB '*[^a-z0-9_-]*' OR length(CAST(NEW.ssh_user AS BLOB)) > 32 OR instr(NEW.ssh_user, char(0)) > 0 OR substr(NEW.ssh_user, 1, 1) NOT GLOB '[a-z_]') BEGIN SELECT RAISE(ABORT, 'Invalid legacy node user.'); END",
        );
        DB::statement(
            "CREATE TRIGGER nodes_sync_user_after_insert AFTER INSERT ON nodes WHEN NEW.user IS NULL OR NEW.user = '' BEGIN UPDATE nodes SET user = CASE WHEN NEW.ssh_user IS NULL OR NEW.ssh_user = '' THEN 'orbit' ELSE NEW.ssh_user END WHERE id = NEW.id; END",
        );
        DB::statement(
            "CREATE TRIGGER nodes_sync_user_after_legacy_update AFTER UPDATE OF ssh_user ON nodes BEGIN UPDATE nodes SET user = CASE WHEN NEW.ssh_user IS NULL OR NEW.ssh_user = '' THEN 'orbit' ELSE NEW.ssh_user END WHERE id = NEW.id; END",
        );
    }

    private static function dropTriggers(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS nodes_validate_legacy_user_before_insert');
        DB::statement('DROP TRIGGER IF EXISTS nodes_validate_legacy_user_before_update');
        DB::statement('DROP TRIGGER IF EXISTS nodes_sync_user_after_insert');
        DB::statement('DROP TRIGGER IF EXISTS nodes_sync_user_after_legacy_update');
    }
};
