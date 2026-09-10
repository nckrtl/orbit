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
        DB::statement('DROP TRIGGER IF EXISTS routes_contract_insert');
        DB::statement('DROP TRIGGER IF EXISTS routes_contract_update');

        Schema::table('routes', static function (Blueprint $table): void {
            $table->string('hostname_change_previous', 253)->nullable()->after('hostname');
            $table->string('hostname_change_target', 253)->nullable()->unique()->after('hostname_change_previous');
            $table
                ->enum('hostname_change_direction', ['forward', 'rollback'])
                ->nullable()
                ->after('hostname_change_target');
            $table->string('hostname_change_step', 64)->nullable()->after('hostname_change_direction');
        });

        $this->createRouteTriggers(withHostnameChange: true);
    }

    public function down(): void
    {
        $unfinished = DB::table('routes')
            ->whereNotNull('hostname_change_target')
            ->orderBy('id')
            ->pluck('id');

        if ($unfinished->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot remove Route hostname change state while operations are unfinished: '
                .$unfinished->implode(', ')
                .'.',
            );
        }

        DB::statement('DROP TRIGGER IF EXISTS routes_contract_insert');
        DB::statement('DROP TRIGGER IF EXISTS routes_contract_update');

        Schema::table('routes', static function (Blueprint $table): void {
            $table->dropUnique(['hostname_change_target']);
            $table->dropColumn([
                'hostname_change_previous',
                'hostname_change_target',
                'hostname_change_direction',
                'hostname_change_step',
            ]);
        });

        $this->createRouteTriggers(withHostnameChange: false);
    }

    private function createRouteTriggers(bool $withHostnameChange): void
    {
        $hostnameChangeInsert = $withHostnameChange
            ? <<<'SQL'
                OR NEW.hostname_change_previous IS NOT NULL
                OR NEW.hostname_change_target IS NOT NULL
                OR NEW.hostname_change_direction IS NOT NULL
                OR NEW.hostname_change_step IS NOT NULL
                OR EXISTS (
                    SELECT 1 FROM routes
                    WHERE routes.hostname_change_target = NEW.hostname
                )
                SQL
            : '';
        $hostnameChangeUpdate = $withHostnameChange
            ? <<<'SQL'
                OR (NEW.hostname_change_target IS NULL) <> (NEW.hostname_change_previous IS NULL)
                OR (NEW.hostname_change_target IS NULL) <> (NEW.hostname_change_direction IS NULL)
                OR (NEW.hostname_change_target IS NULL) <> (NEW.hostname_change_step IS NULL)
                OR EXISTS (
                    SELECT 1 FROM routes
                    WHERE routes.id <> NEW.id
                        AND (
                            routes.hostname = NEW.hostname_change_target
                            OR routes.hostname_change_target = NEW.hostname
                        )
                )
                OR (NEW.hostname_change_target IS NOT NULL AND (
                    NEW.provenance <> 'explicit'
                    OR NEW.publication <> 'private'
                    OR NEW.status <> 'active'
                    OR NEW.hostname_change_previous = NEW.hostname_change_target
                    OR NEW.hostname_change_direction NOT IN ('forward', 'rollback')
                    OR (NEW.hostname_change_direction = 'forward' AND NEW.hostname_change_step NOT IN (
                        'reserved', 'workload-certificate', 'workload-caddy',
                        'router-certificate', 'firewall-policy', 'workload-verified',
                        'router-caddy', 'laravel-url', 'dns-published', 'database-cutover'
                    ))
                    OR (NEW.hostname_change_direction = 'rollback' AND NEW.hostname_change_step NOT IN (
                        'rollback-pending', 'rollback-dns', 'rollback-caddy',
                        'rollback-certificates', 'rollback-laravel-url', 'rolled-back'
                    ))
                    OR (NEW.hostname_change_direction = 'forward'
                        AND NEW.hostname_change_step <> 'database-cutover'
                        AND NEW.hostname <> NEW.hostname_change_previous)
                    OR (NEW.hostname_change_direction = 'forward'
                        AND NEW.hostname_change_step = 'database-cutover'
                        AND NEW.hostname <> NEW.hostname_change_target)
                    OR (NEW.hostname_change_direction = 'rollback'
                        AND NEW.hostname <> NEW.hostname_change_previous)
                    OR (SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.id) <> 1
                    OR NOT EXISTS (
                        SELECT 1
                        FROM route_targets
                        JOIN app_instances
                            ON app_instances.id = route_targets.app_instance_id
                        WHERE route_targets.route_id = NEW.id
                            AND app_instances.environment = 'development'
                            AND app_instances.status = 'active'
                            AND app_instances.source_is_laravel IS NOT NULL
                    )
                ))
                SQL
            : '';
        $failureContract = $withHostnameChange
            ? <<<'SQL'
                OR (NEW.failed_step IS NULL) <> (NEW.error_code IS NULL)
                OR (NEW.hostname_change_target IS NULL AND (
                    (NEW.status = 'failed') <> (NEW.failed_step IS NOT NULL)
                ))
                SQL
            : <<<'SQL'
                OR (NEW.status = 'failed') <> (NEW.failed_step IS NOT NULL AND NEW.error_code IS NOT NULL)
                SQL;

        DB::statement(<<<SQL
            CREATE TRIGGER routes_contract_insert
            BEFORE INSERT ON routes
            WHEN (
                (NEW.node_id IS NULL) = (NEW.cluster_id IS NULL)
                OR (NEW.provenance = 'generated' AND NEW.generation_basis_node_id IS NULL)
                OR (NEW.provenance = 'explicit' AND NEW.generation_basis_node_id IS NOT NULL)
                OR NEW.status NOT IN ('pending', 'active', 'failed')
                OR (NEW.status = 'failed') <> (NEW.failed_step IS NOT NULL AND NEW.error_code IS NOT NULL)
                OR NEW.status = 'active'
                {$hostnameChangeInsert}
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route persistence contract.');
            END
            SQL);

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
                {$failureContract}
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
                    OR (SELECT MIN(position) FROM route_targets WHERE route_id = NEW.id) <> 0
                    OR (SELECT MAX(position) FROM route_targets WHERE route_id = NEW.id)
                        <> (SELECT COUNT(*) - 1 FROM route_targets WHERE route_id = NEW.id)
                ))
                {$hostnameChangeUpdate}
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route persistence contract.');
            END
            SQL);
    }
};
