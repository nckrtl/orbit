<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $replaced = ['routes_contract_insert', 'routes_contract_update'];
        $preserved = [];

        foreach (DB::select('SELECT name, tbl_name, sql FROM sqlite_master WHERE type = ?', ['trigger']) as $trigger) {
            if (! is_string($trigger->name) || ! is_string($trigger->sql)) {
                continue;
            }

            $onRoutes = $trigger->tbl_name === 'routes';
            $referencesRoutes = preg_match('/(?<![A-Za-z0-9_])routes(?![A-Za-z0-9_])/', $trigger->sql) === 1;

            if (! $onRoutes && ! $referencesRoutes) {
                continue;
            }

            if (! in_array($trigger->name, $replaced, true)) {
                $preserved[] = $trigger->sql;
            }

            DB::statement('DROP TRIGGER IF EXISTS '.$trigger->name);
        }

        Schema::create('route_analytics_trackings', static function (Blueprint $table): void {
            $table->unsignedBigInteger('route_id')->primary();
            $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
            $table->timestamps();
            $table->foreign('route_id')->references('id')->on('routes')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            CREATE TRIGGER routes_contract_insert
            BEFORE INSERT ON routes
            WHEN (
                NEW.kind NOT IN ('app', 'custom_proxy', 'analytics_tracking')
                OR (NEW.kind = 'app' AND NEW.app_id IS NULL)
                OR (
                    NEW.kind = 'custom_proxy'
                    AND (
                        NEW.app_id IS NOT NULL
                        OR NEW.node_id IS NULL
                        OR NEW.cluster_id IS NOT NULL
                        OR NEW.provenance <> 'explicit'
                        OR NEW.publication <> 'private'
                        OR NEW.generation_basis_node_id IS NOT NULL
                    )
                )
                OR (
                    NEW.kind = 'analytics_tracking'
                    AND (
                        NEW.app_id IS NOT NULL
                        OR NEW.node_id IS NOT NULL
                        OR NEW.cluster_id IS NULL
                        OR NEW.provenance <> 'explicit'
                        OR NEW.publication <> 'public'
                        OR NEW.generation_basis_node_id IS NOT NULL
                    )
                )
                OR (NEW.node_id IS NULL) = (NEW.cluster_id IS NULL)
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
                NEW.kind <> OLD.kind
                OR NEW.kind NOT IN ('app', 'custom_proxy', 'analytics_tracking')
                OR (NEW.kind = 'app' AND NEW.app_id IS NULL)
                OR (NEW.app_id IS NOT OLD.app_id)
                OR NEW.provenance <> OLD.provenance
                OR NEW.domain <> OLD.domain
                OR (NEW.node_id IS NULL) = (NEW.cluster_id IS NULL)
                OR (
                    NEW.kind = 'custom_proxy'
                    AND (
                        NEW.app_id IS NOT NULL
                        OR NEW.node_id IS NULL
                        OR NEW.cluster_id IS NOT NULL
                        OR NEW.publication <> 'private'
                        OR NEW.public_publication <> 'inactive'
                    )
                )
                OR (
                    NEW.kind = 'analytics_tracking'
                    AND (
                        NEW.app_id IS NOT NULL
                        OR NEW.node_id IS NOT NULL
                        OR NEW.cluster_id IS NULL
                        OR NEW.publication <> 'public'
                    )
                )
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
                    AND NEW.kind = 'app'
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
            CREATE TRIGGER route_targets_reject_analytics_tracking
            BEFORE INSERT ON route_targets
            WHEN (SELECT kind FROM routes WHERE id = NEW.route_id) = 'analytics_tracking'
            BEGIN
                SELECT RAISE(ABORT, 'An analytics tracking Route cannot own App instance targets.');
            END
            SQL);

        foreach ($preserved as $sql) {
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Analytics tracking Route contracts cannot roll back.');
    }
};
