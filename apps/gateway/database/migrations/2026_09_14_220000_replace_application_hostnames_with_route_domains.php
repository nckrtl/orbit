<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var (callable(): void)|null */
    public static $injectFailureAfterSchema = null;

    public function up(): void
    {
        $this->refuseIncompleteHostnameChanges();
        $preservedTriggers = $this->dropAllTriggers();
        $this->renameApplicationEndpointColumns();
        $this->addReplacementColumns();
        $this->dropTransitionEvidence();
        $this->allowReplacementAssociations();

        if (is_callable(self::$injectFailureAfterSchema)) {
            $inject = self::$injectFailureAfterSchema;
            self::$injectFailureAfterSchema = null;
            $inject();
        }

        $this->migrateEnvironmentReferences();
        $this->rebuildTriggers($preservedTriggers);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Application hostname to domain migration cannot roll back.',
        );
    }

    private function refuseIncompleteHostnameChanges(): void
    {
        if (! Schema::hasColumn('routes', 'hostname_change_target')) {
            return;
        }

        $unfinished = DB::table('routes')
            ->whereNotNull('hostname_change_target')
            ->orderBy('id')
            ->pluck('id');

        if ($unfinished->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot migrate application hostnames to domains while a Route hostname change is incomplete: '
                .$unfinished->implode(', ')
                .'.',
            );
        }
    }

    private function renameApplicationEndpointColumns(): void
    {
        if (Schema::hasColumn('routes', 'hostname') && ! Schema::hasColumn('routes', 'domain')) {
            Schema::table('routes', static function (Blueprint $table): void {
                $table->renameColumn('hostname', 'domain');
            });
        }

        if (Schema::hasColumn('instances', 'hostname') && ! Schema::hasColumn('instances', 'domain')) {
            Schema::table('instances', static function (Blueprint $table): void {
                $table->renameColumn('hostname', 'domain');
            });
        }

        if (Schema::hasColumn('workspaces', 'hostname') && ! Schema::hasColumn('workspaces', 'domain')) {
            Schema::table('workspaces', static function (Blueprint $table): void {
                $table->renameColumn('hostname', 'domain');
            });
        }

        if (Schema::hasColumn('app_instances', 'clone_preview_hostname')
            && ! Schema::hasColumn('app_instances', 'clone_preview_domain')) {
            Schema::table('app_instances', static function (Blueprint $table): void {
                $table->renameColumn('clone_preview_hostname', 'clone_preview_domain');
            });
        }

        if (Schema::hasColumn('app_instances', 'registration_route_hostname')
            && ! Schema::hasColumn('app_instances', 'registration_route_domain')) {
            Schema::table('app_instances', static function (Blueprint $table): void {
                $table->renameColumn('registration_route_hostname', 'registration_route_domain');
            });
        }
    }

    private function addReplacementColumns(): void
    {
        if (! Schema::hasColumn('routes', 'replaces_route_id')) {
            Schema::table('routes', static function (Blueprint $table): void {
                $table->foreignId('replaces_route_id')->nullable()->constrained('routes')->nullOnDelete();
                $table->foreignId('replaced_by_route_id')->nullable()->constrained('routes')->nullOnDelete();
                $table->string('replacement_step', 64)->nullable();
            });
        }
    }

    private function dropTransitionEvidence(): void
    {
        if (! Schema::hasColumn('routes', 'hostname_change_target')) {
            return;
        }

        Schema::table('routes', static function (Blueprint $table): void {
            $table->dropUnique(['hostname_change_target']);
            $table->dropColumn([
                'hostname_change_previous',
                'hostname_change_target',
                'hostname_change_direction',
                'hostname_change_step',
            ]);
        });
    }

    private function allowReplacementAssociations(): void
    {
        $index = collect(DB::select("PRAGMA index_list('route_targets')"))
            ->first(static function (object $index): bool {
                if (! property_exists($index, 'unique') || (int) $index->unique !== 1) {
                    return false;
                }

                $columns = collect(DB::select('PRAGMA index_info('.$index->name.')'))
                    ->pluck('name')
                    ->all();

                return $columns === ['app_instance_id'];
            });

        if ($index === null) {
            return;
        }

        Schema::table('route_targets', static function (Blueprint $table): void {
            $table->dropUnique(['app_instance_id']);
        });
    }

    private function migrateEnvironmentReferences(): void
    {
        if (! Schema::hasTable('app_instance_environment_values')) {
            return;
        }

        foreach (DB::table('app_instance_environment_values')->orderBy('id')->get() as $row) {
            if (! is_string($row->env_value) || $row->env_value === '') {
                continue;
            }

            try {
                $value = Crypt::decryptString($row->env_value);
            } catch (Throwable) {
                continue;
            }

            $updated = str_replace('{{app_instance.hostname}}', '{{app_instance.domain}}', $value);

            if ($updated === $value) {
                continue;
            }

            DB::table('app_instance_environment_values')
                ->where('id', $row->id)
                ->update(['env_value' => Crypt::encryptString($updated)]);
        }
    }

    /** @return list<string> */
    private function dropAllTriggers(): array
    {
        $replaced = [
            'app_instances_active_route_update',
            'route_targets_active_delete',
            'route_targets_active_update',
            'routes_active_target_delete',
            'route_targets_contract_update',
            'route_targets_contract_insert',
            'routes_contract_update',
            'routes_contract_insert',
            'production_route_target_nodes_update',
            'production_route_target_roles_update',
            'production_route_target_roles_delete',
            'production_route_target_instances_update',
        ];
        $preserved = [];

        foreach (DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' ORDER BY name") as $trigger) {
            if (! is_string($trigger->name) || ! is_string($trigger->sql)) {
                continue;
            }

            DB::statement("DROP TRIGGER IF EXISTS {$trigger->name}");

            if (! in_array($trigger->name, $replaced, true)) {
                $preserved[] = $trigger->sql;
            }
        }

        return $preserved;
    }

    /** @param list<string> $preservedTriggers */
    private function rebuildTriggers(array $preservedTriggers): void
    {

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

        DB::statement(<<<'SQL'
            CREATE TRIGGER route_targets_contract_insert
            BEFORE INSERT ON route_targets
            WHEN (
                NEW.position < 0
                OR (SELECT app_id FROM app_instances WHERE id = NEW.app_instance_id)
                    <> (SELECT app_id FROM routes WHERE id = NEW.route_id)
                OR (
                    (SELECT node_id FROM routes WHERE id = NEW.route_id) IS NOT NULL
                    AND (SELECT node_id FROM routes WHERE id = NEW.route_id)
                        <> (SELECT node_id FROM app_instances WHERE id = NEW.app_instance_id)
                )
                OR (SELECT COUNT(*) FROM route_targets WHERE app_instance_id = NEW.app_instance_id) >= 2
                OR (
                    EXISTS (SELECT 1 FROM route_targets WHERE app_instance_id = NEW.app_instance_id)
                    AND NOT EXISTS (
                        SELECT 1
                        FROM route_targets AS existing
                        JOIN routes AS existing_route ON existing_route.id = existing.route_id
                        JOIN routes AS new_route ON new_route.id = NEW.route_id
                        WHERE existing.app_instance_id = NEW.app_instance_id
                            AND (
                                new_route.replaces_route_id = existing.route_id
                                OR existing_route.replaces_route_id = NEW.route_id
                                OR new_route.replaced_by_route_id = existing.route_id
                                OR existing_route.replaced_by_route_id = NEW.route_id
                            )
                    )
                )
                OR EXISTS (
                    SELECT 1
                    FROM route_targets AS existing
                    JOIN app_instances AS existing_instance ON existing_instance.id = existing.app_instance_id
                    JOIN app_instances AS proposed_instance ON proposed_instance.id = NEW.app_instance_id
                    WHERE existing.route_id = NEW.route_id
                        AND existing_instance.node_id = proposed_instance.node_id
                )
                OR (
                    (SELECT provenance FROM routes WHERE id = NEW.route_id) = 'generated'
                    AND EXISTS (SELECT 1 FROM route_targets WHERE route_id = NEW.route_id)
                )
                OR (
                    EXISTS (SELECT 1 FROM route_targets WHERE route_id = NEW.route_id)
                    AND (SELECT provenance FROM routes WHERE id = NEW.route_id) = 'explicit'
                    AND (SELECT cluster_id FROM routes WHERE id = NEW.route_id) IS NOT NULL
                    AND (
                        (SELECT environment FROM app_instances WHERE id = NEW.app_instance_id) <> 'production'
                        OR (SELECT app_id FROM app_instances WHERE id = NEW.app_instance_id)
                            <> (SELECT app_id FROM routes WHERE id = NEW.route_id)
                        OR NOT EXISTS (
                            SELECT 1 FROM active_app_prod_nodes
                            WHERE node_id = (SELECT node_id FROM app_instances WHERE id = NEW.app_instance_id)
                                AND cluster_id IS (SELECT cluster_id FROM routes WHERE id = NEW.route_id)
                                AND active = 1
                        )
                    )
                )
                OR (
                    (SELECT status FROM routes WHERE id = NEW.route_id) IN ('active', 'activating', 'retiring')
                    AND NEW.position <> (
                        SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.route_id
                    )
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route target contract.');
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
                    (SELECT status FROM routes WHERE id = NEW.route_id) IN ('active', 'activating', 'retiring')
                    AND NEW.position >= (
                        SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.route_id
                    )
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route target contract.');
            END
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instances_active_route_update
            BEFORE UPDATE OF status ON app_instances
            WHEN NEW.status = 'active' AND (
                SELECT COUNT(*)
                FROM route_targets
                JOIN routes ON routes.id = route_targets.route_id
                WHERE route_targets.app_instance_id = NEW.id
                    AND routes.status IN ('active', 'activating')
            ) <> 1
            BEGIN
                SELECT RAISE(ABORT, 'An active AppInstance requires exactly one authoritative Route.');
            END
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER route_targets_active_delete
            BEFORE DELETE ON route_targets
            WHEN (
                (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'active'
                AND (SELECT status FROM routes WHERE id = OLD.route_id) IN ('active', 'activating')
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
                SELECT RAISE(ABORT, 'An active AppInstance requires exactly one authoritative Route.');
            END
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER route_targets_active_update
            BEFORE UPDATE OF app_instance_id ON route_targets
            WHEN OLD.app_instance_id <> NEW.app_instance_id
                AND (
                    (
                        (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'active'
                        AND (SELECT status FROM routes WHERE id = OLD.route_id) IN ('active', 'activating')
                    )
                    OR (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'removing'
                )
            BEGIN
                SELECT RAISE(ABORT, 'An active AppInstance requires exactly one authoritative Route.');
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
                        (app_instances.status = 'active' AND OLD.status IN ('active', 'activating'))
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
                SELECT RAISE(ABORT, 'An active AppInstance requires exactly one authoritative Route.');
            END
            SQL);

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

        foreach ($preservedTriggers as $sql) {
            DB::statement($sql);
        }
    }
};
