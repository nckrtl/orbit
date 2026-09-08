<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->dropRouteTriggers();

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table
                ->enum('status', ['reserved', 'checkout_prepared', 'source_resolved', 'active', 'removing'])
                ->default('reserved')
                ->change();
        });

        Schema::create('app_instance_removals', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('requested_app_instance_id');
            $table->string('requested_name');
            $table->boolean('force');
            $table->string('inventory_digest', 64);
            $table->unsignedInteger('total');
            $table->enum('status', ['removing', 'failed', 'completed'])->index();
            $table->string('current_step', 64)->nullable();
            $table->string('failed_step', 64)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->timestamps();
        });

        Schema::create('app_instance_removal_members', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('app_instance_removal_id');
            $table->unsignedInteger('position');
            $table->unsignedBigInteger('app_instance_id');
            $table->unsignedBigInteger('app_id');
            $table->unsignedBigInteger('node_id');
            $table->unsignedBigInteger('route_id')->nullable();
            $table->string('name');
            $table->string('environment');
            $table->string('source_layout');
            $table->string('repository_identity')->nullable();
            $table->text('checkout_path')->nullable();
            $table->text('root')->nullable();
            $table->string('branch')->nullable();
            $table->string('starting_commit', 64)->nullable();
            $table->text('common_repository_path')->nullable();
            $table->string('source_identity')->nullable();
            $table->json('linked_worktree_paths');
            $table->string('source_digest', 64);
            $table->timestamp('source_prepared_at')->nullable();
            $table->timestamp('route_cleared_at')->nullable();
            $table->string('route_outcome')->nullable();
            $table->timestamp('source_finalized_at')->nullable();
            $table->string('finalization_receipt', 64)->nullable();
            $table->timestamp('runtime_cleaned_at')->nullable();
            $table->timestamp('row_deleted_at')->nullable();
            $table->timestamps();

            $table
                ->foreign('app_instance_removal_id')
                ->references('id')
                ->on('app_instance_removals')
                ->cascadeOnDelete();
            $table->unique(['app_instance_removal_id', 'position']);
            $table->unique(['app_instance_removal_id', 'app_instance_id']);
        });

        Schema::create('active_app_prod_nodes', static function (Blueprint $table): void {
            $table->unsignedBigInteger('node_id')->primary();
            $table->unsignedBigInteger('cluster_id')->nullable();
            $table->boolean('active')->default(false);
        });
        DB::table('active_app_prod_nodes')->insertUsing(
            ['node_id', 'cluster_id'],
            DB::table('nodes')->select(['id', 'cluster_id']),
        );
        DB::table('active_app_prod_nodes')
            ->whereIn(
                'node_id',
                DB::table('node_roles')
                    ->select('node_id')
                    ->where('role', 'app-prod')
                    ->where('status', 'active'),
            )
            ->update(['active' => true]);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX app_instance_removal_members_live_unique
            ON app_instance_removal_members (app_instance_id)
            WHERE row_deleted_at IS NULL
            SQL);
        $this->createAppProdProjectionTriggers();
        $this->createRemovalTriggers();
        $this->createRouteTriggers(true);
    }

    public function down(): void
    {
        $unfinished = DB::table('app_instance_removals')
            ->where('status', '<>', 'completed')
            ->exists();

        if ($unfinished) {
            throw new RuntimeException('Cannot roll back while an AppInstance removal is unfinished.');
        }

        $this->dropRouteTriggers();
        $this->dropAppProdProjectionTriggers();
        DB::statement('DROP TRIGGER IF EXISTS app_instance_removal_members_immutable');
        DB::statement('DROP TRIGGER IF EXISTS app_instance_removal_members_insert');
        DB::statement('DROP TRIGGER IF EXISTS app_instance_removal_members_delete');
        DB::statement('DROP TRIGGER IF EXISTS app_instance_removals_immutable');
        DB::statement('DROP TRIGGER IF EXISTS app_instance_removals_insert');
        DB::statement('DROP TRIGGER IF EXISTS app_instance_removals_delete');
        Schema::dropIfExists('active_app_prod_nodes');
        Schema::dropIfExists('app_instance_removal_members');
        Schema::dropIfExists('app_instance_removals');

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table
                ->enum('status', ['reserved', 'checkout_prepared', 'source_resolved', 'active'])
                ->default('reserved')
                ->change();
        });

        $this->createRouteTriggers(false);
    }

    private function createRemovalTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instance_removals_insert
            BEFORE INSERT ON app_instance_removals
            WHEN NEW.total < 1
                OR NEW.status <> 'removing'
                OR NEW.current_step <> 'source_preparation'
                OR NEW.failed_step IS NOT NULL
                OR NEW.error_code IS NOT NULL
                OR NOT EXISTS (
                    SELECT 1 FROM app_instances
                    WHERE id = NEW.requested_app_instance_id AND status = 'active'
                )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid AppInstance removal contract.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instance_removals_immutable
            BEFORE UPDATE ON app_instance_removals
            WHEN NEW.id <> OLD.id
                OR NEW.requested_app_instance_id <> OLD.requested_app_instance_id
                OR NEW.requested_name <> OLD.requested_name
                OR NEW.force <> OLD.force
                OR NEW.inventory_digest <> OLD.inventory_digest
                OR NEW.total <> OLD.total
                OR NEW.status NOT IN ('removing', 'failed', 'completed')
                OR NEW.current_step NOT IN (
                    'source_preparation',
                    'route_target_clear',
                    'source_finalization',
                    'runtime_cleanup',
                    'row_deletion'
                )
                OR (NEW.status = 'completed') <> (NEW.current_step IS NULL)
                OR (NEW.failed_step IS NOT NULL AND NEW.failed_step NOT IN (
                    'source_preparation',
                    'route_target_clear',
                    'source_finalization',
                    'runtime_cleanup',
                    'row_deletion'
                ))
                OR (NEW.status = 'failed') <> (NEW.failed_step IS NOT NULL AND NEW.error_code IS NOT NULL)
                OR (OLD.status = 'completed' AND NEW.status <> 'completed')
            BEGIN
                SELECT RAISE(ABORT, 'Invalid AppInstance removal contract.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instance_removals_delete
            BEFORE DELETE ON app_instance_removals
            BEGIN
                SELECT RAISE(ABORT, 'AppInstance removal evidence is immutable.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instance_removal_members_insert
            BEFORE INSERT ON app_instance_removal_members
            WHEN NEW.position < 0
                OR NEW.position >= (
                    SELECT total FROM app_instance_removals WHERE id = NEW.app_instance_removal_id
                )
                OR (SELECT COUNT(*) FROM app_instance_removal_members
                    WHERE app_instance_removal_id = NEW.app_instance_removal_id) >= (
                        SELECT total FROM app_instance_removals WHERE id = NEW.app_instance_removal_id
                    )
                OR NEW.environment NOT IN ('development', 'production')
                OR NEW.source_layout NOT IN ('checkout', 'worktree')
                OR json_valid(NEW.linked_worktree_paths) <> 1
                OR json_type(NEW.linked_worktree_paths) <> 'array'
                OR NEW.source_digest = ''
                OR NEW.source_prepared_at IS NOT NULL
                OR NEW.route_cleared_at IS NOT NULL
                OR NEW.route_outcome IS NOT NULL
                OR NEW.source_finalized_at IS NOT NULL
                OR NEW.finalization_receipt IS NOT NULL
                OR NEW.runtime_cleaned_at IS NOT NULL
                OR NEW.row_deleted_at IS NOT NULL
                OR NOT EXISTS (
                    SELECT 1
                    FROM app_instances
                    WHERE app_instances.id = NEW.app_instance_id
                        AND app_instances.app_id = NEW.app_id
                        AND app_instances.node_id = NEW.node_id
                        AND app_instances.name = NEW.name
                        AND app_instances.environment = NEW.environment
                        AND app_instances.status = 'active'
                )
                OR NOT EXISTS (
                    SELECT 1 FROM route_targets
                    WHERE route_targets.route_id = NEW.route_id
                        AND route_targets.app_instance_id = NEW.app_instance_id
                )
                OR (NEW.environment = 'development' AND (
                    NEW.repository_identity IS NULL
                    OR NEW.checkout_path IS NULL
                    OR NEW.root IS NULL
                    OR NEW.branch IS NULL
                    OR NEW.starting_commit IS NULL
                    OR NEW.common_repository_path IS NULL
                    OR NEW.source_identity IS NULL
                ))
            BEGIN
                SELECT RAISE(ABORT, 'Invalid AppInstance removal member contract.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instance_removal_members_immutable
            BEFORE UPDATE ON app_instance_removal_members
            WHEN NEW.app_instance_removal_id <> OLD.app_instance_removal_id
                OR NEW.position <> OLD.position
                OR NEW.app_instance_id <> OLD.app_instance_id
                OR NEW.app_id <> OLD.app_id
                OR NEW.node_id <> OLD.node_id
                OR NEW.route_id IS NOT OLD.route_id
                OR NEW.name <> OLD.name
                OR NEW.environment <> OLD.environment
                OR NEW.source_layout <> OLD.source_layout
                OR NEW.repository_identity IS NOT OLD.repository_identity
                OR NEW.checkout_path IS NOT OLD.checkout_path
                OR NEW.root IS NOT OLD.root
                OR NEW.branch IS NOT OLD.branch
                OR NEW.starting_commit IS NOT OLD.starting_commit
                OR NEW.common_repository_path IS NOT OLD.common_repository_path
                OR NEW.source_identity IS NOT OLD.source_identity
                OR NEW.linked_worktree_paths <> OLD.linked_worktree_paths
                OR NEW.source_digest <> OLD.source_digest
                OR (OLD.source_prepared_at IS NOT NULL AND NEW.source_prepared_at IS NULL)
                OR (OLD.route_cleared_at IS NOT NULL AND NEW.route_cleared_at IS NULL)
                OR (OLD.route_outcome IS NOT NULL AND NEW.route_outcome IS NOT OLD.route_outcome)
                OR (OLD.source_finalized_at IS NOT NULL AND NEW.source_finalized_at IS NULL)
                OR (OLD.finalization_receipt IS NOT NULL AND NEW.finalization_receipt IS NOT OLD.finalization_receipt)
                OR (OLD.runtime_cleaned_at IS NOT NULL AND NEW.runtime_cleaned_at IS NULL)
                OR (OLD.row_deleted_at IS NOT NULL AND NEW.row_deleted_at IS NULL)
                OR (NEW.route_cleared_at IS NOT NULL AND NEW.source_prepared_at IS NULL)
                OR (NEW.route_outcome IS NOT NULL) <> (NEW.route_cleared_at IS NOT NULL)
                OR (NEW.route_outcome IS NOT NULL AND NEW.route_outcome NOT IN ('retained', 'deleted'))
                OR (NEW.source_finalized_at IS NOT NULL AND NEW.route_cleared_at IS NULL)
                OR (NEW.finalization_receipt IS NOT NULL) <> (NEW.source_finalized_at IS NOT NULL)
                OR (NEW.runtime_cleaned_at IS NOT NULL AND NEW.source_finalized_at IS NULL)
                OR (NEW.row_deleted_at IS NOT NULL AND NEW.runtime_cleaned_at IS NULL)
            BEGIN
                SELECT RAISE(ABORT, 'Invalid AppInstance removal member contract.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instance_removal_members_delete
            BEFORE DELETE ON app_instance_removal_members
            BEGIN
                SELECT RAISE(ABORT, 'AppInstance removal member evidence is immutable.');
            END
            SQL);
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
            AFTER UPDATE OF id, cluster_id ON nodes
            BEGIN
                UPDATE active_app_prod_nodes
                SET node_id = NEW.id, cluster_id = NEW.cluster_id
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
            CREATE TRIGGER active_app_prod_nodes_insert
            AFTER INSERT ON node_roles
            WHEN NEW.role = 'app-prod' AND NEW.status = 'active'
            BEGIN
                UPDATE active_app_prod_nodes SET active = 1 WHERE node_id = NEW.node_id;
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER active_app_prod_nodes_update
            AFTER UPDATE OF node_id, role, status ON node_roles
            BEGIN
                UPDATE active_app_prod_nodes
                SET active = 0
                WHERE node_id = OLD.node_id
                    AND OLD.role = 'app-prod'
                    AND OLD.status = 'active';
                UPDATE active_app_prod_nodes
                SET active = 1
                WHERE node_id = NEW.node_id
                    AND NEW.role = 'app-prod'
                    AND NEW.status = 'active';
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER active_app_prod_nodes_delete
            AFTER DELETE ON node_roles
            BEGIN
                UPDATE active_app_prod_nodes
                SET active = 0
                WHERE node_id = OLD.node_id
                    AND OLD.role = 'app-prod'
                    AND OLD.status = 'active';
            END
            SQL);
    }

    private function dropAppProdProjectionTriggers(): void
    {
        foreach ([
            'active_app_prod_nodes_node_insert',
            'active_app_prod_nodes_node_update',
            'active_app_prod_nodes_node_delete',
            'active_app_prod_nodes_insert',
            'active_app_prod_nodes_update',
            'active_app_prod_nodes_delete',
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

        $sharedRouteContract = $sharedProductionRoutes
            ? <<<'SQL'
                OR (NEW.status = 'active' AND (
                    (SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.id) = 0
                    OR (
                        (SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.id) > 1
                        AND (
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
                                        app_instances.environment <> 'production'
                                        OR active_app_prod_nodes.cluster_id IS NOT NEW.cluster_id
                                        OR active_app_prod_nodes.active <> 1
                                    )
                            )
                        )
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
                {$sharedRouteContract}
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
                        OR (SELECT cluster_id FROM active_app_prod_nodes WHERE node_id = (
                            SELECT node_id FROM app_instances WHERE id = NEW.app_instance_id
                        )) IS NOT (SELECT cluster_id FROM routes WHERE id = NEW.route_id)
                        OR 1 <> COALESCE((
                            SELECT active FROM active_app_prod_nodes
                            WHERE node_id = (
                                SELECT node_id FROM app_instances WHERE id = NEW.app_instance_id
                            )
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

        $contract = <<<'SQL'
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
                {$contract}
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
                {$contract}
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
            WHEN (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'active'
                AND (SELECT status FROM routes WHERE id = OLD.route_id) = 'active'
            BEGIN
                SELECT RAISE(ABORT, 'An active AppInstance requires exactly one Route.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER route_targets_active_update
            BEFORE UPDATE OF app_instance_id ON route_targets
            WHEN OLD.app_instance_id <> NEW.app_instance_id
                AND (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'active'
                AND (SELECT status FROM routes WHERE id = OLD.route_id) = 'active'
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
                    AND app_instances.status = 'active'
                    AND OLD.status = 'active'
            )
            BEGIN
                SELECT RAISE(ABORT, 'An active AppInstance requires exactly one Route.');
            END
            SQL);
    }
};
