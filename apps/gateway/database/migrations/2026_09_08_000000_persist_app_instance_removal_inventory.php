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

        $this->changeAppInstanceStatus([
            'reserved',
            'checkout_prepared',
            'source_resolved',
            'active',
            'removing',
        ]);

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

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX app_instance_removal_members_live_unique
            ON app_instance_removal_members (app_instance_id)
            WHERE row_deleted_at IS NULL
            SQL);
        $this->createRemovalTriggers();
        $this->createAppInstanceRemovalLifecycleTriggers();
        $this->createRouteTriggers(true);
    }

    public function down(): void
    {
        if (DB::table('app_instance_removals')->exists()) {
            throw new RuntimeException('Cannot roll back while AppInstance removal evidence exists.');
        }

        $removing = DB::table('app_instances')
            ->where('status', 'removing')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($removing !== []) {
            throw new RuntimeException(
                'Cannot roll back while AppInstances are removing: '.implode(', ', $removing),
            );
        }

        $this->dropRouteTriggers();
        $this->dropRemovalTriggers();
        Schema::dropIfExists('app_instance_removal_members');
        Schema::dropIfExists('app_instance_removals');

        $this->changeAppInstanceStatus([
            'reserved',
            'checkout_prepared',
            'source_resolved',
            'active',
        ]);

        $this->createRouteTriggers(false);
    }

    private function createRemovalTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instance_removals_insert
            BEFORE INSERT ON app_instance_removals
            WHEN NEW.id = ''
                OR NEW.requested_name = ''
                OR NEW.force NOT IN (0, 1)
                OR length(NEW.inventory_digest) <> 64
                OR NEW.total < 1
                OR NEW.status <> 'removing'
                OR NEW.current_step <> 'source_preparation'
                OR NEW.failed_step IS NOT NULL
                OR NEW.error_code IS NOT NULL
                OR NOT EXISTS (
                    SELECT 1
                    FROM app_instances
                    WHERE id = NEW.requested_app_instance_id
                        AND name = NEW.requested_name
                        AND status = 'active'
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
                OR (NEW.current_step IS NOT NULL AND NEW.current_step NOT IN (
                    'source_preparation',
                    'route_target_clear',
                    'source_finalization',
                    'runtime_cleanup',
                    'row_deletion'
                ))
                OR (NEW.failed_step IS NOT NULL AND NEW.failed_step NOT IN (
                    'source_preparation',
                    'route_target_clear',
                    'source_finalization',
                    'runtime_cleanup',
                    'row_deletion'
                ))
                OR (NEW.status = 'removing' AND (
                    NEW.current_step IS NULL
                    OR NEW.failed_step IS NOT NULL
                    OR NEW.error_code IS NOT NULL
                ))
                OR (NEW.status = 'failed' AND (
                    NEW.current_step IS NULL
                    OR NEW.failed_step IS NOT NEW.current_step
                    OR NEW.error_code IS NULL
                    OR NEW.error_code = ''
                ))
                OR (NEW.status = 'completed' AND (
                    NEW.current_step IS NOT NULL
                    OR NEW.failed_step IS NOT NULL
                    OR NEW.error_code IS NOT NULL
                    OR (SELECT COUNT(*) FROM app_instance_removal_members
                        WHERE app_instance_removal_id = NEW.id) <> NEW.total
                    OR EXISTS (
                        SELECT 1 FROM app_instance_removal_members
                        WHERE app_instance_removal_id = NEW.id AND row_deleted_at IS NULL
                    )
                ))
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
            WHEN NOT EXISTS (
                    SELECT 1
                    FROM app_instance_removals
                    WHERE id = NEW.app_instance_removal_id
                        AND status = 'removing'
                        AND current_step = 'source_preparation'
                        AND failed_step IS NULL
                        AND error_code IS NULL
                )
                OR NEW.position < 0
                OR NEW.position >= (
                    SELECT total FROM app_instance_removals WHERE id = NEW.app_instance_removal_id
                )
                OR (SELECT COUNT(*) FROM app_instance_removal_members
                    WHERE app_instance_removal_id = NEW.app_instance_removal_id) >= (
                        SELECT total FROM app_instance_removals WHERE id = NEW.app_instance_removal_id
                    )
                OR (
                    (SELECT COUNT(*) FROM app_instance_removal_members
                        WHERE app_instance_removal_id = NEW.app_instance_removal_id) + 1
                        = (SELECT total FROM app_instance_removals
                            WHERE id = NEW.app_instance_removal_id)
                    AND NEW.app_instance_id <> (
                        SELECT requested_app_instance_id FROM app_instance_removals
                        WHERE id = NEW.app_instance_removal_id
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM app_instance_removal_members
                        WHERE app_instance_removal_id = NEW.app_instance_removal_id
                            AND app_instance_id = (
                                SELECT requested_app_instance_id FROM app_instance_removals
                                WHERE id = NEW.app_instance_removal_id
                            )
                    )
                )
                OR NEW.environment NOT IN ('development', 'production')
                OR NEW.source_layout NOT IN ('checkout', 'worktree')
                OR json_valid(NEW.linked_worktree_paths) <> 1
                OR json_type(NEW.linked_worktree_paths) <> 'array'
                OR length(NEW.source_digest) <> 64
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
                    JOIN apps ON apps.id = app_instances.app_id
                    WHERE app_instances.id = NEW.app_instance_id
                        AND app_instances.app_id = NEW.app_id
                        AND app_instances.node_id = NEW.node_id
                        AND app_instances.name = NEW.name
                        AND app_instances.environment = NEW.environment
                        AND app_instances.source_layout = NEW.source_layout
                        AND app_instances.checkout_path IS NEW.checkout_path
                        AND COALESCE(app_instances.root, apps.root) IS NEW.root
                        AND app_instances.branch IS NEW.branch
                        AND app_instances.starting_commit IS NEW.starting_commit
                        AND apps.repository_identity IS NEW.repository_identity
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
                OR (OLD.source_prepared_at IS NOT NULL AND NEW.source_prepared_at IS NOT OLD.source_prepared_at)
                OR (OLD.route_cleared_at IS NOT NULL AND NEW.route_cleared_at IS NOT OLD.route_cleared_at)
                OR (OLD.route_outcome IS NOT NULL AND NEW.route_outcome IS NOT OLD.route_outcome)
                OR (OLD.source_finalized_at IS NOT NULL AND NEW.source_finalized_at IS NOT OLD.source_finalized_at)
                OR (OLD.finalization_receipt IS NOT NULL
                    AND NEW.finalization_receipt IS NOT OLD.finalization_receipt)
                OR (OLD.runtime_cleaned_at IS NOT NULL AND NEW.runtime_cleaned_at IS NOT OLD.runtime_cleaned_at)
                OR (OLD.row_deleted_at IS NOT NULL AND NEW.row_deleted_at IS NOT OLD.row_deleted_at)
                OR (NEW.source_prepared_at IS NOT NULL AND (
                    (SELECT status FROM app_instances WHERE id = NEW.app_instance_id) <> 'removing'
                    OR (SELECT status FROM app_instance_removals
                        WHERE id = NEW.app_instance_removal_id) <> 'removing'
                ))
                OR (NEW.route_cleared_at IS NOT NULL AND NEW.source_prepared_at IS NULL)
                OR (NEW.route_outcome IS NOT NULL) <> (NEW.route_cleared_at IS NOT NULL)
                OR (NEW.route_outcome IS NOT NULL AND NEW.route_outcome NOT IN ('retained', 'deleted'))
                OR (NEW.route_cleared_at IS NOT NULL AND EXISTS (
                    SELECT 1 FROM route_targets WHERE app_instance_id = NEW.app_instance_id
                ))
                OR (NEW.route_outcome = 'deleted' AND EXISTS (
                    SELECT 1 FROM routes WHERE id = NEW.route_id
                ))
                OR (NEW.route_outcome = 'retained' AND NOT EXISTS (
                    SELECT 1 FROM routes WHERE id = NEW.route_id
                ))
                OR (NEW.source_finalized_at IS NOT NULL AND NEW.route_cleared_at IS NULL)
                OR (NEW.finalization_receipt IS NOT NULL) <> (NEW.source_finalized_at IS NOT NULL)
                OR (NEW.runtime_cleaned_at IS NOT NULL AND NEW.source_finalized_at IS NULL)
                OR (NEW.row_deleted_at IS NOT NULL AND NEW.runtime_cleaned_at IS NULL)
                OR (NEW.row_deleted_at IS NOT NULL AND EXISTS (
                    SELECT 1 FROM app_instances WHERE id = NEW.app_instance_id
                ))
                OR (SELECT status FROM app_instance_removals
                    WHERE id = NEW.app_instance_removal_id) = 'completed'
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

    /** @param list<string> $states */
    private function changeAppInstanceStatus(array $states): void
    {
        $routeTargets = DB::table('route_targets')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $target): array => (array) $target)
            ->all();

        DB::table('route_targets')->delete();
        Schema::table('app_instances', static function (Blueprint $table) use ($states): void {
            $table->enum('status', $states)->default('reserved')->change();
        });

        if ($routeTargets !== []) {
            DB::table('route_targets')->insert($routeTargets);
        }
    }

    private function createAppInstanceRemovalLifecycleTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instances_removal_status_insert
            BEFORE INSERT ON app_instances
            WHEN NEW.status = 'removing'
            BEGIN
                SELECT RAISE(ABORT, 'A removing AppInstance requires recorded removal membership.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instances_removal_status_update
            BEFORE UPDATE OF status ON app_instances
            WHEN (OLD.status = 'removing' AND NEW.status <> 'removing')
                OR (NEW.status = 'removing' AND OLD.status <> 'removing' AND (
                    OLD.status <> 'active'
                    OR NOT EXISTS (
                        SELECT 1
                        FROM app_instance_removal_members
                        JOIN app_instance_removals
                            ON app_instance_removals.id = app_instance_removal_members.app_instance_removal_id
                        WHERE app_instance_removal_members.app_instance_id = NEW.id
                            AND app_instance_removal_members.row_deleted_at IS NULL
                            AND app_instance_removals.status = 'removing'
                            AND (SELECT COUNT(*) FROM app_instance_removal_members AS inventory
                                WHERE inventory.app_instance_removal_id = app_instance_removals.id)
                                = app_instance_removals.total
                    )
                ))
            BEGIN
                SELECT RAISE(ABORT, 'A removing AppInstance requires recorded removal membership.');
            END
            SQL);
    }

    private function dropRemovalTriggers(): void
    {
        foreach ([
            'app_instances_removal_status_insert',
            'app_instances_removal_status_update',
            'app_instance_removal_members_immutable',
            'app_instance_removal_members_insert',
            'app_instance_removal_members_delete',
            'app_instance_removals_immutable',
            'app_instance_removals_insert',
            'app_instance_removals_delete',
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

    private function createRouteTriggers(bool $recordedRemoval): void
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
        DB::statement(<<<'SQL'
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
                OR (NEW.status = 'active' AND (
                    SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.id
                ) <> 1)
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route persistence contract.');
            END
            SQL);

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
                OR (
                    (SELECT provenance FROM routes WHERE id = NEW.route_id) = 'generated'
                    AND EXISTS (SELECT 1 FROM route_targets WHERE route_id = NEW.route_id)
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route target contract.');
            END
            SQL);
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
                OR (
                    (SELECT provenance FROM routes WHERE id = NEW.route_id) = 'generated'
                    AND EXISTS (SELECT 1 FROM route_targets WHERE route_id = NEW.route_id AND id <> OLD.id)
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
                SELECT COUNT(*) FROM route_targets WHERE app_instance_id = NEW.id
            ) <> 1
            BEGIN
                SELECT RAISE(ABORT, 'An active AppInstance requires exactly one Route.');
            END
            SQL);

        $targetDeleteGuard = $recordedRemoval
            ? <<<'SQL'
                (
                    (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'active'
                    AND (SELECT status FROM routes WHERE id = OLD.route_id) = 'active'
                )
                OR (
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
                SQL
            : <<<'SQL'
                (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'active'
                    AND (SELECT status FROM routes WHERE id = OLD.route_id) = 'active'
                SQL;

        DB::statement(<<<SQL
            CREATE TRIGGER route_targets_active_delete
            BEFORE DELETE ON route_targets
            WHEN {$targetDeleteGuard}
            BEGIN
                SELECT RAISE(ABORT, 'An active AppInstance requires exactly one Route.');
            END
            SQL);

        $targetUpdateGuard = $recordedRemoval
            ? <<<'SQL'
                (
                    (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'active'
                    AND (SELECT status FROM routes WHERE id = OLD.route_id) = 'active'
                )
                OR (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'removing'
                SQL
            : <<<'SQL'
                (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'active'
                    AND (SELECT status FROM routes WHERE id = OLD.route_id) = 'active'
                SQL;

        DB::statement(<<<SQL
            CREATE TRIGGER route_targets_active_update
            BEFORE UPDATE OF app_instance_id ON route_targets
            WHEN OLD.app_instance_id <> NEW.app_instance_id
                AND ({$targetUpdateGuard})
            BEGIN
                SELECT RAISE(ABORT, 'An active AppInstance requires exactly one Route.');
            END
            SQL);

        $routeDeleteGuard = $recordedRemoval
            ? <<<'SQL'
                (app_instances.status = 'active' AND OLD.status = 'active')
                OR (
                    app_instances.status = 'removing'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM app_instance_removal_members
                        JOIN app_instance_removals
                            ON app_instance_removals.id = app_instance_removal_members.app_instance_removal_id
                        WHERE app_instance_removal_members.app_instance_id = app_instances.id
                            AND app_instance_removal_members.route_id = OLD.id
                            AND app_instance_removal_members.source_prepared_at IS NOT NULL
                            AND app_instance_removal_members.route_cleared_at IS NULL
                            AND app_instance_removal_members.row_deleted_at IS NULL
                            AND app_instance_removals.status IN ('removing', 'failed')
                    )
                )
                SQL
            : <<<'SQL'
                app_instances.status = 'active' AND OLD.status = 'active'
                SQL;

        DB::statement(<<<SQL
            CREATE TRIGGER routes_active_target_delete
            BEFORE DELETE ON routes
            WHEN EXISTS (
                SELECT 1 FROM route_targets
                JOIN app_instances ON app_instances.id = route_targets.app_instance_id
                WHERE route_targets.route_id = OLD.id
                    AND ({$routeDeleteGuard})
            )
            BEGIN
                SELECT RAISE(ABORT, 'An active AppInstance requires exactly one Route.');
            END
            SQL);
    }
};
