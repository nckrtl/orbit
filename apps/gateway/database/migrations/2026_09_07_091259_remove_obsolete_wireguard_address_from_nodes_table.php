<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::table('nodes')->whereRaw('wireguard_ip IS NOT wireguard_address')->exists()) {
            throw new RuntimeException('Cannot remove wireguard_address while Node WireGuard identities differ.');
        }

        DB::statement('DROP TRIGGER IF EXISTS nodes_wireguard_address_update');
        DB::statement('DROP TRIGGER IF EXISTS nodes_wireguard_ip_update');
        DB::statement('DROP TRIGGER IF EXISTS nodes_wireguard_identity_insert');

        Schema::table('nodes', static function (Blueprint $table): void {
            $table->dropUnique('nodes_wireguard_address_unique');
            $table->dropColumn('wireguard_address');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', static function (Blueprint $table): void {
            $table->string('wireguard_address')->nullable();
        });

        DB::table('nodes')->update(['wireguard_address' => DB::raw('wireguard_ip')]);

        Schema::table('nodes', static function (Blueprint $table): void {
            $table->unique('wireguard_address');
        });

        DB::statement(<<<'SQL'
            CREATE TRIGGER nodes_wireguard_identity_insert
            AFTER INSERT ON nodes
            WHEN NEW.wireguard_ip IS NOT NEW.wireguard_address
            BEGIN
                UPDATE nodes
                SET wireguard_ip = COALESCE(NEW.wireguard_ip, NEW.wireguard_address),
                    wireguard_address = COALESCE(NEW.wireguard_ip, NEW.wireguard_address)
                WHERE id = NEW.id;
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER nodes_wireguard_ip_update
            AFTER UPDATE OF wireguard_ip ON nodes
            WHEN NEW.wireguard_ip IS NOT NEW.wireguard_address
            BEGIN
                UPDATE nodes SET wireguard_address = NEW.wireguard_ip WHERE id = NEW.id;
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER nodes_wireguard_address_update
            AFTER UPDATE OF wireguard_address ON nodes
            WHEN NEW.wireguard_ip IS OLD.wireguard_ip
                AND NEW.wireguard_ip IS NOT NEW.wireguard_address
            BEGIN
                UPDATE nodes SET wireguard_ip = NEW.wireguard_address WHERE id = NEW.id;
            END
            SQL);
    }
};
