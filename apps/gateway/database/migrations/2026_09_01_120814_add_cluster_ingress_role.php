<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS node_roles_router_cluster_update');
        DB::statement('DROP TRIGGER IF EXISTS node_roles_router_cluster_insert');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX node_roles_cluster_ingress_active_unique
            ON node_roles (cluster_id)
            WHERE role = 'ingress' AND status = 'active'
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER node_roles_cluster_ownership_insert
            BEFORE INSERT ON node_roles
            WHEN (
                (NEW.role IN ('router', 'ingress') AND (
                    NEW.cluster_id IS NULL
                    OR NEW.cluster_id IS NOT (SELECT cluster_id FROM nodes WHERE id = NEW.node_id)
                ))
                OR (NEW.role NOT IN ('router', 'ingress') AND NEW.cluster_id IS NOT NULL)
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Cluster role ownership.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER node_roles_cluster_ownership_update
            BEFORE UPDATE OF node_id, cluster_id, role ON node_roles
            WHEN (
                (NEW.role IN ('router', 'ingress') AND (
                    NEW.cluster_id IS NULL
                    OR NEW.cluster_id IS NOT (SELECT cluster_id FROM nodes WHERE id = NEW.node_id)
                ))
                OR (NEW.role NOT IN ('router', 'ingress') AND NEW.cluster_id IS NOT NULL)
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Cluster role ownership.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER nodes_cluster_role_ownership_update
            BEFORE UPDATE OF cluster_id ON nodes
            WHEN NEW.cluster_id IS NOT OLD.cluster_id
                AND EXISTS (
                    SELECT 1
                    FROM node_roles
                    WHERE node_id = OLD.id
                        AND role IN ('router', 'ingress')
                )
            BEGIN
                SELECT RAISE(ABORT, 'Cluster role assignment prevents membership changes.');
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS nodes_cluster_role_ownership_update');
        DB::statement('DROP TRIGGER IF EXISTS node_roles_cluster_ownership_update');
        DB::statement('DROP TRIGGER IF EXISTS node_roles_cluster_ownership_insert');
        DB::statement('DROP INDEX IF EXISTS node_roles_cluster_ingress_active_unique');

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
    }
};
