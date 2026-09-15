<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS route_targets_route_id_position_unique');
        DB::statement('DROP TRIGGER IF EXISTS routes_contract_update');
        DB::statement('DROP TRIGGER IF EXISTS route_targets_contract_update');

        DB::statement(<<<'SQL'
            CREATE TRIGGER routes_contract_update
            BEFORE UPDATE ON routes
            WHEN (
                NEW.app_id <> OLD.app_id
                OR NEW.provenance <> OLD.provenance
                OR NEW.domain <> OLD.domain
                OR (NEW.node_id IS NULL) = (NEW.cluster_id IS NULL)
                OR (NEW.provenance = 'generated' AND NEW.generation_basis_node_id IS NULL)
                OR (NEW.provenance = 'explicit' AND NEW.generation_basis_node_id IS NOT NULL)
                OR NEW.status NOT IN ('pending', 'active', 'activating', 'retiring', 'failed')
                OR (NEW.failed_step IS NULL) <> (NEW.error_code IS NULL)
                OR (NEW.status = 'failed' AND NEW.failed_step IS NULL)
                OR (
                    NEW.status = 'active'
                    AND NEW.failed_step IS NOT NULL
                    AND NEW.replacement_step IS NULL
                    AND NEW.target_set_step IS NULL
                )
                OR (NEW.replaces_route_id IS NOT NULL AND NEW.replaced_by_route_id IS NOT NULL)
                OR NEW.public_publication NOT IN ('inactive', 'active')
                OR (NEW.public_publication = 'active' AND NEW.publication <> 'public')
                OR (NEW.public_publication = 'active' AND NEW.status NOT IN ('active', 'activating'))
                OR (
                    NEW.status IN ('active', 'activating')
                    AND (
                        SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.id
                    ) = 0
                    AND NOT (
                        NEW.provenance = 'explicit'
                        AND NEW.cluster_id IS NOT NULL
                        AND NEW.target_set_step IS NOT NULL
                    )
                )
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
                    OR (SELECT MIN(position) FROM route_targets WHERE route_id = NEW.id) <> 0
                    OR (SELECT MAX(position) FROM route_targets WHERE route_id = NEW.id)
                        <> (SELECT COUNT(*) - 1 FROM route_targets WHERE route_id = NEW.id)
                ))
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route persistence contract.');
            END
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER route_targets_contract_update
            BEFORE UPDATE OF route_id, app_instance_id, position ON route_targets
            WHEN (
                NEW.position < 0
                OR (SELECT app_id FROM app_instances WHERE id = NEW.app_instance_id)
                    <> (SELECT app_id FROM routes WHERE id = NEW.route_id)
                OR (
                    (SELECT node_id FROM routes WHERE id = NEW.route_id) IS NOT NULL
                    AND (SELECT node_id FROM routes WHERE id = NEW.route_id)
                        <> (SELECT node_id FROM app_instances WHERE id = NEW.app_instance_id)
                )
                OR EXISTS (
                    SELECT 1 FROM route_targets
                    WHERE app_instance_id = NEW.app_instance_id
                        AND id <> OLD.id
                        AND NOT EXISTS (
                            SELECT 1
                            FROM routes AS existing_route
                            JOIN routes AS new_route ON new_route.id = NEW.route_id
                            WHERE existing_route.id = route_targets.route_id
                                AND (
                                    new_route.replaces_route_id = existing_route.id
                                    OR existing_route.replaces_route_id = NEW.route_id
                                    OR new_route.replaced_by_route_id = existing_route.id
                                    OR existing_route.replaced_by_route_id = NEW.route_id
                                )
                        )
                )
                OR (
                    NEW.route_id = OLD.route_id
                    AND (SELECT status FROM routes WHERE id = NEW.route_id) IN ('active', 'activating', 'retiring')
                    AND NEW.position >= (
                        SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.route_id
                    )
                )
                OR (
                    NEW.route_id <> OLD.route_id
                    AND (SELECT status FROM routes WHERE id = NEW.route_id) IN ('active', 'activating', 'retiring')
                    AND NEW.position > (
                        SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.route_id
                    )
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route target contract.');
            END
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Production target-set mutation contracts cannot roll back.');
    }
};
