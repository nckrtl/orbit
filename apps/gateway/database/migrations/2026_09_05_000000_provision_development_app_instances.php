<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $invalid = DB::table('app_instances')
            ->leftJoin('route_targets', 'route_targets.app_instance_id', '=', 'app_instances.id')
            ->where('app_instances.status', 'active')
            ->groupBy('app_instances.id')
            ->havingRaw('COUNT(route_targets.id) <> 1')
            ->orderBy('app_instances.id')
            ->pluck('app_instances.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($invalid !== []) {
            throw new \RuntimeException(
                'Active AppInstances must have exactly one Route before upgrade: '.implode(', ', $invalid),
            );
        }

        DB::statement('DROP TRIGGER IF EXISTS routes_contract_insert');
        DB::statement('DROP TRIGGER IF EXISTS routes_contract_update');
        DB::statement('DROP TRIGGER IF EXISTS route_targets_contract_insert');
        DB::statement('DROP TRIGGER IF EXISTS route_targets_contract_update');

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->string('selected_php_version', 8)->nullable()->after('starting_commit');
            $table->string('provisioning_step', 64)->nullable()->after('selected_php_version');
            $table->string('failed_step', 64)->nullable()->after('provisioning_step');
            $table->string('error_code', 128)->nullable()->after('failed_step');
        });

        Schema::table('routes', static function (Blueprint $table): void {
            $table->enum('status', ['pending', 'active', 'failed'])->default('pending')->change();
        });

        Schema::table('route_targets', static function (Blueprint $table): void {
            $table->unique('app_instance_id');
        });

        $this->createRouteTriggers();
        $this->createAssociationTriggers();
    }

    public function down(): void
    {
        foreach ([
            'app_instances_active_route_insert',
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

        Schema::table('route_targets', static function (Blueprint $table): void {
            $table->dropUnique(['app_instance_id']);
        });
        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->dropColumn(['selected_php_version', 'provisioning_step', 'failed_step', 'error_code']);
        });
    }

    private function createRouteTriggers(): void
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
    }

    private function createAssociationTriggers(): void
    {
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
