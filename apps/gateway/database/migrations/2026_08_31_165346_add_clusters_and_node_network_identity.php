<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clusters', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('tld')->nullable()->unique();
            $table->enum('state', ['inactive', 'active'])->default('inactive')->index();
            $table->timestamps();
        });

        Schema::table('nodes', static function (Blueprint $table): void {
            $table->foreignId('cluster_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('wireguard_ip')->nullable()->unique();
            $table->string('lan_ip')->nullable();
        });

        DB::table('nodes')->update(['wireguard_ip' => DB::raw('wireguard_address')]);

        Schema::table('node_roles', static function (Blueprint $table): void {
            $table->foreignId('cluster_id')->nullable()->constrained()->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX nodes_cluster_lan_ip_unique
            ON nodes (cluster_id, lan_ip)
            WHERE cluster_id IS NOT NULL AND lan_ip IS NOT NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX node_roles_cluster_router_unique
            ON node_roles (cluster_id)
            WHERE role = 'router' AND status = 'active'
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER node_roles_router_cluster_insert
            BEFORE INSERT ON node_roles
            WHEN (
                (NEW.role = 'router' AND (
                    NEW.cluster_id IS NULL
                    OR NEW.cluster_id IS NOT (SELECT cluster_id FROM nodes WHERE id = NEW.node_id)
                ))
                OR (NEW.role <> 'router' AND NEW.cluster_id IS NOT NULL)
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Router Cluster ownership.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER node_roles_router_cluster_update
            BEFORE UPDATE OF node_id, cluster_id, role ON node_roles
            WHEN (
                (NEW.role = 'router' AND (
                    NEW.cluster_id IS NULL
                    OR NEW.cluster_id IS NOT (SELECT cluster_id FROM nodes WHERE id = NEW.node_id)
                ))
                OR (NEW.role <> 'router' AND NEW.cluster_id IS NOT NULL)
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Router Cluster ownership.');
            END
            SQL);
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS nodes_wireguard_address_update');
        DB::statement('DROP TRIGGER IF EXISTS nodes_wireguard_ip_update');
        DB::statement('DROP TRIGGER IF EXISTS nodes_wireguard_identity_insert');
        DB::statement('DROP TRIGGER IF EXISTS node_roles_router_cluster_update');
        DB::statement('DROP TRIGGER IF EXISTS node_roles_router_cluster_insert');
        DB::statement('DROP INDEX IF EXISTS node_roles_cluster_router_unique');
        DB::statement('DROP INDEX IF EXISTS nodes_cluster_lan_ip_unique');

        Schema::table('node_roles', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cluster_id');
        });

        Schema::table('nodes', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cluster_id');
            $table->dropUnique('nodes_wireguard_ip_unique');
            $table->dropColumn(['wireguard_ip', 'lan_ip']);
        });

        Schema::dropIfExists('clusters');
    }
};
