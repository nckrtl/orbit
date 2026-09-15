<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', static function (Blueprint $table): void {
            $table->enum('public_publication', ['inactive', 'active'])->default('inactive');
        });

        DB::statement('DROP TRIGGER IF EXISTS routes_contract_insert');
        DB::statement('DROP TRIGGER IF EXISTS routes_contract_update');

        DB::statement(<<<'SQL'
            CREATE TRIGGER routes_contract_insert
            BEFORE INSERT ON routes
            WHEN (
                (NEW.node_id IS NULL) = (NEW.cluster_id IS NULL)
                OR (NEW.provenance = 'generated' AND NEW.generation_basis_node_id IS NULL)
                OR (NEW.provenance = 'explicit' AND NEW.generation_basis_node_id IS NOT NULL)
                OR NEW.status NOT IN ('pending', 'active', 'activating', 'retiring', 'failed')
                OR NEW.status IN ('active', 'activating', 'retiring')
                OR (NEW.status = 'failed') <> (NEW.failed_step IS NOT NULL AND NEW.error_code IS NOT NULL)
                OR (NEW.replaces_route_id IS NOT NULL AND NEW.replaced_by_route_id IS NOT NULL)
                OR (NEW.replaces_route_id IS NOT NULL AND NEW.replacement_step IS NULL)
                OR NEW.public_publication <> 'inactive'
                OR EXISTS (
                    SELECT 1 FROM routes
                    WHERE routes.domain = NEW.domain
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route persistence contract.');
            END
            SQL);

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
                OR (NEW.status = 'active' AND NEW.failed_step IS NOT NULL)
                OR (NEW.replaces_route_id IS NOT NULL AND NEW.replaced_by_route_id IS NOT NULL)
                OR NEW.public_publication NOT IN ('inactive', 'active')
                OR (NEW.public_publication = 'active' AND NEW.publication <> 'public')
                OR (NEW.public_publication = 'active' AND NEW.status NOT IN ('active', 'activating'))
                OR (NEW.status IN ('active', 'activating') AND (
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
                    OR (SELECT MIN(position) FROM route_targets WHERE route_id = NEW.id) <> 0
                    OR (SELECT MAX(position) FROM route_targets WHERE route_id = NEW.id)
                        <> (SELECT COUNT(*) - 1 FROM route_targets WHERE route_id = NEW.id)
                ))
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route persistence contract.');
            END
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Public Route publication cannot roll back.');
    }
};
