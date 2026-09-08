<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $invalid = $this->incompatibleProductionRouteIds();

        if ($invalid !== []) {
            throw new RuntimeException(
                'Cannot enable production Route target sets with incompatible Routes: '.implode(', ', $invalid),
            );
        }

        $this->dropRouteTriggers();

        Schema::create('active_app_prod_nodes', static function (Blueprint $table): void {
            $table->unsignedBigInteger('node_id')->primary();
            $table->unsignedBigInteger('cluster_id')->nullable();
            $table->boolean('active')->default(false);
        });
        DB::statement(<<<'SQL'
            INSERT INTO active_app_prod_nodes (node_id, cluster_id, active)
            SELECT nodes.id, nodes.cluster_id,
                CASE WHEN nodes.status = 'active' AND EXISTS (
                    SELECT 1 FROM node_roles
                    WHERE node_roles.node_id = nodes.id
                        AND node_roles.role = 'app-prod'
                        AND node_roles.status = 'active'
                ) THEN 1 ELSE 0 END
            FROM nodes
            SQL);

        $this->createAppProdProjectionTriggers();
        $this->createProductionTargetMutationGuards();
        $this->createRouteTriggers(true);
    }

    public function down(): void
    {
        $shared = DB::table('routes')
            ->whereRaw('(SELECT COUNT(*) FROM route_targets WHERE route_targets.route_id = routes.id) > 1')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($shared !== []) {
            throw new RuntimeException(
                'Cannot roll back while production Routes have multiple targets: '.implode(', ', $shared),
            );
        }

        $this->dropRouteTriggers();
        $this->dropProductionTargetMutationGuards();
        $this->dropAppProdProjectionTriggers();
        Schema::dropIfExists('active_app_prod_nodes');
        $this->createRouteTriggers(false);
    }

    /** @return list<string> */
    private function incompatibleProductionRouteIds(): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT routes.id
            FROM routes
            WHERE (SELECT COUNT(*) FROM route_targets WHERE route_targets.route_id = routes.id) > 1
                AND (
                    routes.provenance <> 'explicit'
                    OR routes.cluster_id IS NULL
                    OR EXISTS (
                        SELECT 1
                        FROM route_targets
                        JOIN app_instances ON app_instances.id = route_targets.app_instance_id
                        JOIN nodes ON nodes.id = app_instances.node_id
                        WHERE route_targets.route_id = routes.id
                            AND (
                                app_instances.app_id <> routes.app_id
                                OR app_instances.environment <> 'production'
                                OR nodes.status <> 'active'
                                OR nodes.cluster_id IS NOT routes.cluster_id
                                OR NOT EXISTS (
                                    SELECT 1 FROM node_roles
                                    WHERE node_roles.node_id = nodes.id
                                        AND node_roles.role = 'app-prod'
                                        AND node_roles.status = 'active'
                                )
                            )
                    )
                    OR (
                        SELECT COUNT(DISTINCT app_instances.node_id)
                        FROM route_targets
                        JOIN app_instances ON app_instances.id = route_targets.app_instance_id
                        WHERE route_targets.route_id = routes.id
                    ) <> (
                        SELECT COUNT(*) FROM route_targets WHERE route_targets.route_id = routes.id
                    )
                    OR (
                        SELECT MIN(position) FROM route_targets WHERE route_targets.route_id = routes.id
                    ) <> 0
                    OR (
                        SELECT MAX(position) FROM route_targets WHERE route_targets.route_id = routes.id
                    ) <> (
                        SELECT COUNT(*) - 1 FROM route_targets WHERE route_targets.route_id = routes.id
                    )
                )
            ORDER BY routes.id
            SQL))
            ->map(static fn (object $row): string => (string) $row->id)
            ->all();
    }

    private function createAppProdProjectionTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER active_app_prod_nodes_node_insert
            AFTER INSERT ON nodes
            BEGIN
                INSERT INTO active_app_prod_nodes (node_id, cluster_id, active)
                VALUES (NEW.id, NEW.cluster_id, 0);
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER active_app_prod_nodes_node_update
            AFTER UPDATE OF id, cluster_id, status ON nodes
            BEGIN
                UPDATE active_app_prod_nodes
                SET node_id = NEW.id,
                    cluster_id = NEW.cluster_id,
                    active = CASE WHEN NEW.status = 'active' AND EXISTS (
                        SELECT 1 FROM node_roles
                        WHERE node_roles.node_id = NEW.id
                            AND node_roles.role = 'app-prod'
                            AND node_roles.status = 'active'
                    ) THEN 1 ELSE 0 END
                WHERE node_id = OLD.id;
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER active_app_prod_nodes_node_delete
            AFTER DELETE ON nodes
            BEGIN
                DELETE FROM active_app_prod_nodes WHERE node_id = OLD.id;
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER active_app_prod_nodes_role_insert
            AFTER INSERT ON node_roles
            BEGIN
                UPDATE active_app_prod_nodes
                SET active = CASE WHEN EXISTS (
                    SELECT 1 FROM nodes
                    WHERE nodes.id = NEW.node_id AND nodes.status = 'active'
                ) AND EXISTS (
                    SELECT 1 FROM node_roles
                    WHERE node_roles.node_id = NEW.node_id
                        AND node_roles.role = 'app-prod'
                        AND node_roles.status = 'active'
                ) THEN 1 ELSE 0 END
                WHERE node_id = NEW.node_id;
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER active_app_prod_nodes_role_update
            AFTER UPDATE OF node_id, role, status ON node_roles
            BEGIN
                UPDATE active_app_prod_nodes
                SET active = CASE WHEN EXISTS (
                    SELECT 1 FROM nodes
                    WHERE nodes.id = OLD.node_id AND nodes.status = 'active'
                ) AND EXISTS (
                    SELECT 1 FROM node_roles
                    WHERE node_roles.node_id = OLD.node_id
                        AND node_roles.role = 'app-prod'
                        AND node_roles.status = 'active'
                ) THEN 1 ELSE 0 END
                WHERE node_id = OLD.node_id;
                UPDATE active_app_prod_nodes
                SET active = CASE WHEN EXISTS (
                    SELECT 1 FROM nodes
                    WHERE nodes.id = NEW.node_id AND nodes.status = 'active'
                ) AND EXISTS (
                    SELECT 1 FROM node_roles
                    WHERE node_roles.node_id = NEW.node_id
                        AND node_roles.role = 'app-prod'
                        AND node_roles.status = 'active'
                ) THEN 1 ELSE 0 END
                WHERE node_id = NEW.node_id;
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER active_app_prod_nodes_role_delete
            AFTER DELETE ON node_roles
            BEGIN
                UPDATE active_app_prod_nodes
                SET active = CASE WHEN EXISTS (
                    SELECT 1 FROM nodes
                    WHERE nodes.id = OLD.node_id AND nodes.status = 'active'
                ) AND EXISTS (
                    SELECT 1 FROM node_roles
                    WHERE node_roles.node_id = OLD.node_id
                        AND node_roles.role = 'app-prod'
                        AND node_roles.status = 'active'
                ) THEN 1 ELSE 0 END
                WHERE node_id = OLD.node_id;
            END
            SQL);
    }

    private function dropAppProdProjectionTriggers(): void
    {
        foreach ([
            'active_app_prod_nodes_node_insert',
            'active_app_prod_nodes_node_update',
            'active_app_prod_nodes_node_delete',
            'active_app_prod_nodes_role_insert',
            'active_app_prod_nodes_role_update',
            'active_app_prod_nodes_role_delete',
        ] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function createProductionTargetMutationGuards(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_route_target_nodes_update
            BEFORE UPDATE OF cluster_id, status ON nodes
            WHEN EXISTS (
                SELECT 1
                FROM app_instances
                JOIN route_targets ON route_targets.app_instance_id = app_instances.id
                JOIN routes ON routes.id = route_targets.route_id
                WHERE app_instances.node_id = OLD.id
                    AND (SELECT COUNT(*) FROM route_targets AS members WHERE members.route_id = routes.id) > 1
                    AND (NEW.status <> 'active' OR NEW.cluster_id IS NOT routes.cluster_id)
            )
            BEGIN
                SELECT RAISE(ABORT, 'Shared production Route target Node cannot become inactive or change Cluster.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_route_target_roles_update
            BEFORE UPDATE OF node_id, role, status ON node_roles
            WHEN OLD.node_id IN (
                SELECT app_instances.node_id
                FROM app_instances
                JOIN route_targets ON route_targets.app_instance_id = app_instances.id
                WHERE (
                    SELECT COUNT(*) FROM route_targets AS members
                    WHERE members.route_id = route_targets.route_id
                ) > 1
            )
                AND NOT (
                    (NEW.node_id = OLD.node_id AND NEW.role = 'app-prod' AND NEW.status = 'active')
                    OR EXISTS (
                        SELECT 1 FROM node_roles AS other
                        WHERE other.node_id = OLD.node_id
                            AND other.id <> OLD.id
                            AND other.role = 'app-prod'
                            AND other.status = 'active'
                    )
                )
            BEGIN
                SELECT RAISE(ABORT, 'Shared production Route target Node requires an active app-prod role.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_route_target_roles_delete
            BEFORE DELETE ON node_roles
            WHEN OLD.role = 'app-prod'
                AND OLD.status = 'active'
                AND OLD.node_id IN (
                    SELECT app_instances.node_id
                    FROM app_instances
                    JOIN route_targets ON route_targets.app_instance_id = app_instances.id
                    WHERE (
                        SELECT COUNT(*) FROM route_targets AS members
                        WHERE members.route_id = route_targets.route_id
                    ) > 1
                )
                AND NOT EXISTS (
                    SELECT 1 FROM node_roles AS other
                    WHERE other.node_id = OLD.node_id
                        AND other.id <> OLD.id
                        AND other.role = 'app-prod'
                        AND other.status = 'active'
                )
            BEGIN
                SELECT RAISE(ABORT, 'Shared production Route target Node requires an active app-prod role.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER production_route_target_instances_update
            BEFORE UPDATE OF app_id, node_id, environment ON app_instances
            WHEN EXISTS (
                SELECT 1
                FROM route_targets
                JOIN routes ON routes.id = route_targets.route_id
                WHERE route_targets.app_instance_id = OLD.id
                    AND (SELECT COUNT(*) FROM route_targets AS members WHERE members.route_id = routes.id) > 1
                    AND (
                        NEW.app_id <> routes.app_id
                        OR NEW.environment <> 'production'
                        OR 1 <> COALESCE((
                            SELECT active FROM active_app_prod_nodes WHERE node_id = NEW.node_id
                        ), 0)
                        OR (SELECT cluster_id FROM active_app_prod_nodes WHERE node_id = NEW.node_id)
                            IS NOT routes.cluster_id
                        OR EXISTS (
                            SELECT 1
                            FROM route_targets AS sibling
                            JOIN app_instances AS sibling_instance
                                ON sibling_instance.id = sibling.app_instance_id
                            WHERE sibling.route_id = routes.id
                                AND sibling.app_instance_id <> OLD.id
                                AND sibling_instance.node_id = NEW.node_id
                        )
                    )
            )
            BEGIN
                SELECT RAISE(ABORT, 'Shared production Route target AppInstance cannot invalidate its target set.');
            END
            SQL);
    }

    private function dropProductionTargetMutationGuards(): void
    {
        foreach ([
            'production_route_target_nodes_update',
            'production_route_target_roles_update',
            'production_route_target_roles_delete',
            'production_route_target_instances_update',
        ] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function dropRouteTriggers(): void
    {
        foreach ([
            'app_instances_active_route_update',
            'route_targets_active_delete',
            'route_targets_active_update',
            'routes_active_target_delete',
            'route_targets_contract_update',
            'route_targets_contract_insert',
            'routes_contract_update',
            'routes_contract_insert',
        ] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function createRouteTriggers(bool $sharedProductionRoutes): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER routes_contract_insert
            BEFORE INSERT ON routes
            WHEN (
                (NEW.node_id IS NULL) = (NEW.cluster_id IS NULL)
                OR (NEW.provenance = 'generated' AND NEW.generation_basis_node_id IS NULL)
                OR (NEW.provenance = 'explicit' AND NEW.generation_basis_node_id IS NOT NULL)
                OR NEW.status NOT IN ('pending', 'active', 'failed')
                OR (NEW.status = 'failed') <> (NEW.failed_step IS NOT NULL AND NEW.error_code IS NOT NULL)
                OR NEW.status = 'active'
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route persistence contract.');
            END
            SQL);

        $activeTargetSet = $sharedProductionRoutes
            ? <<<'SQL'
                OR (NEW.status = 'active' AND (
                    SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.id
                ) = 0)
                OR ((SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.id) > 1 AND (
                    NEW.provenance <> 'explicit'
                    OR NEW.cluster_id IS NULL
                    OR EXISTS (
                        SELECT 1
                        FROM route_targets
                        JOIN app_instances ON app_instances.id = route_targets.app_instance_id
                        JOIN active_app_prod_nodes
                            ON active_app_prod_nodes.node_id = app_instances.node_id
                        WHERE route_targets.route_id = NEW.id
                            AND (
                                app_instances.app_id <> NEW.app_id
                                OR app_instances.environment <> 'production'
                                OR active_app_prod_nodes.cluster_id IS NOT NEW.cluster_id
                                OR active_app_prod_nodes.active <> 1
                            )
                    )
                    OR (
                        SELECT MIN(position) FROM route_targets WHERE route_id = NEW.id
                    ) <> 0
                    OR (
                        SELECT MAX(position) FROM route_targets WHERE route_id = NEW.id
                    ) <> (
                        SELECT COUNT(*) - 1 FROM route_targets WHERE route_id = NEW.id
                    )
                ))
                SQL
            : <<<'SQL'
                OR (NEW.status = 'active' AND (
                    SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.id
                ) <> 1)
                SQL;

        DB::statement(<<<SQL
            CREATE TRIGGER routes_contract_update
            BEFORE UPDATE ON routes
            WHEN (
                NEW.app_id <> OLD.app_id
                OR NEW.provenance <> OLD.provenance
                OR (NEW.node_id IS NULL) = (NEW.cluster_id IS NULL)
                OR (NEW.provenance = 'generated' AND NEW.generation_basis_node_id IS NULL)
                OR (NEW.provenance = 'explicit' AND NEW.generation_basis_node_id IS NOT NULL)
                OR NEW.status NOT IN ('pending', 'active', 'failed')
                OR (NEW.status = 'failed') <> (NEW.failed_step IS NOT NULL AND NEW.error_code IS NOT NULL)
                {$activeTargetSet}
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route persistence contract.');
            END
            SQL);

        $sharedTargetContract = $sharedProductionRoutes
            ? <<<'SQL'
                OR (
                    EXISTS (SELECT 1 FROM route_targets WHERE route_id = NEW.route_id)
                    AND (
                        (SELECT provenance FROM routes WHERE id = NEW.route_id) <> 'explicit'
                        OR (SELECT cluster_id FROM routes WHERE id = NEW.route_id) IS NULL
                        OR (SELECT environment FROM app_instances WHERE id = NEW.app_instance_id) <> 'production'
                        OR (SELECT app_id FROM app_instances WHERE id = NEW.app_instance_id)
                            <> (SELECT app_id FROM routes WHERE id = NEW.route_id)
                        OR (SELECT cluster_id FROM active_app_prod_nodes WHERE node_id = (
                            SELECT node_id FROM app_instances WHERE id = NEW.app_instance_id
                        )) IS NOT (SELECT cluster_id FROM routes WHERE id = NEW.route_id)
                        OR 1 <> COALESCE((
                            SELECT active FROM active_app_prod_nodes
                            WHERE node_id = (SELECT node_id FROM app_instances WHERE id = NEW.app_instance_id)
                        ), 0)
                        OR EXISTS (
                            SELECT 1
                            FROM route_targets AS existing
                            JOIN app_instances AS existing_instance ON existing_instance.id = existing.app_instance_id
                            JOIN active_app_prod_nodes AS existing_node
                                ON existing_node.node_id = existing_instance.node_id
                            WHERE existing.route_id = NEW.route_id
                                AND (
                                    existing_instance.environment <> 'production'
                                    OR existing_node.cluster_id IS NOT (
                                        SELECT cluster_id FROM routes WHERE id = NEW.route_id
                                    )
                                    OR existing_node.active <> 1
                                )
                        )
                    )
                )
                SQL
            : <<<'SQL'
                OR (
                    (SELECT provenance FROM routes WHERE id = NEW.route_id) = 'generated'
                    AND EXISTS (SELECT 1 FROM route_targets WHERE route_id = NEW.route_id)
                )
                SQL;

        $baseTargetContract = <<<'SQL'
                    NEW.position < 0
                    OR (SELECT app_id FROM app_instances WHERE id = NEW.app_instance_id)
                        <> (SELECT app_id FROM routes WHERE id = NEW.route_id)
                    OR (
                        (SELECT node_id FROM routes WHERE id = NEW.route_id) IS NOT NULL
                        AND (SELECT node_id FROM routes WHERE id = NEW.route_id)
                            <> (SELECT node_id FROM app_instances WHERE id = NEW.app_instance_id)
                    )
            SQL;

        DB::statement(<<<SQL
            CREATE TRIGGER route_targets_contract_insert
            BEFORE INSERT ON route_targets
            WHEN (
                {$baseTargetContract}
                OR EXISTS (SELECT 1 FROM route_targets WHERE app_instance_id = NEW.app_instance_id)
                OR EXISTS (
                    SELECT 1
                    FROM route_targets AS existing
                    JOIN app_instances AS existing_instance ON existing_instance.id = existing.app_instance_id
                    JOIN app_instances AS proposed_instance ON proposed_instance.id = NEW.app_instance_id
                    WHERE existing.route_id = NEW.route_id
                        AND existing_instance.node_id = proposed_instance.node_id
                )
                {$sharedTargetContract}
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route target contract.');
            END
            SQL);

        $sharedUpdateContract = str_replace(
            'EXISTS (SELECT 1 FROM route_targets WHERE route_id = NEW.route_id)',
            'EXISTS (SELECT 1 FROM route_targets WHERE route_id = NEW.route_id AND id <> OLD.id)',
            $sharedTargetContract,
        );

        DB::statement(<<<SQL
            CREATE TRIGGER route_targets_contract_update
            BEFORE UPDATE OF route_id, app_instance_id, position ON route_targets
            WHEN (
                {$baseTargetContract}
                OR EXISTS (
                    SELECT 1 FROM route_targets
                    WHERE app_instance_id = NEW.app_instance_id AND id <> OLD.id
                )
                OR EXISTS (
                    SELECT 1
                    FROM route_targets AS existing
                    JOIN app_instances AS existing_instance ON existing_instance.id = existing.app_instance_id
                    JOIN app_instances AS proposed_instance ON proposed_instance.id = NEW.app_instance_id
                    WHERE existing.route_id = NEW.route_id
                        AND existing.id <> OLD.id
                        AND existing_instance.node_id = proposed_instance.node_id
                )
                {$sharedUpdateContract}
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route target contract.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instances_active_route_update
            BEFORE UPDATE OF status ON app_instances
            WHEN NEW.status = 'active' AND (
                SELECT COUNT(*) FROM route_targets WHERE app_instance_id = NEW.id
            ) <> 1
            BEGIN
                SELECT RAISE(ABORT, 'An active AppInstance requires exactly one Route.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER route_targets_active_delete
            BEFORE DELETE ON route_targets
            WHEN (
                (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'active'
                AND (SELECT status FROM routes WHERE id = OLD.route_id) = 'active'
            ) OR (
                (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'removing'
                AND NOT EXISTS (
                    SELECT 1
                    FROM app_instance_removal_members
                    JOIN app_instance_removals
                        ON app_instance_removals.id = app_instance_removal_members.app_instance_removal_id
                    WHERE app_instance_removal_members.app_instance_id = OLD.app_instance_id
                        AND app_instance_removal_members.route_id = OLD.route_id
                        AND app_instance_removal_members.source_prepared_at IS NOT NULL
                        AND app_instance_removal_members.route_cleared_at IS NULL
                        AND app_instance_removal_members.row_deleted_at IS NULL
                        AND app_instance_removals.status IN ('removing', 'failed')
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'An active AppInstance requires exactly one Route.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER route_targets_active_update
            BEFORE UPDATE OF app_instance_id ON route_targets
            WHEN OLD.app_instance_id <> NEW.app_instance_id
                AND (
                    (
                        (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'active'
                        AND (SELECT status FROM routes WHERE id = OLD.route_id) = 'active'
                    )
                    OR (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'removing'
                )
            BEGIN
                SELECT RAISE(ABORT, 'An active AppInstance requires exactly one Route.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER routes_active_target_delete
            BEFORE DELETE ON routes
            WHEN EXISTS (
                SELECT 1 FROM route_targets
                JOIN app_instances ON app_instances.id = route_targets.app_instance_id
                WHERE route_targets.route_id = OLD.id
                    AND (
                        (app_instances.status = 'active' AND OLD.status = 'active')
                        OR (
                            app_instances.status = 'removing'
                            AND NOT EXISTS (
                                SELECT 1
                                FROM app_instance_removal_members
                                JOIN app_instance_removals ON app_instance_removals.id =
                                    app_instance_removal_members.app_instance_removal_id
                                WHERE app_instance_removal_members.app_instance_id = app_instances.id
                                    AND app_instance_removal_members.route_id = OLD.id
                                    AND app_instance_removal_members.source_prepared_at IS NOT NULL
                                    AND app_instance_removal_members.route_cleared_at IS NULL
                                    AND app_instance_removal_members.row_deleted_at IS NULL
                                    AND app_instance_removals.status IN ('removing', 'failed')
                            )
                        )
                    )
            )
            BEGIN
                SELECT RAISE(ABORT, 'An active AppInstance requires exactly one Route.');
            END
            SQL);
    }
};
