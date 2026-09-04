<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('routes', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained()->restrictOnDelete();
            $table->foreignId('node_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cluster_id')->nullable()->constrained()->restrictOnDelete();
            $table
                ->foreignId('generation_basis_node_id')
                ->nullable()
                ->constrained('nodes')
                ->restrictOnDelete();
            $table->string('hostname', 253)->unique();
            $table->enum('provenance', ['generated', 'explicit']);
            $table->enum('publication', ['private', 'public']);
            $table->enum('status', ['pending'])->default('pending')->index();
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
        });

        Schema::create('route_targets', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_instance_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['route_id', 'position']);
            $table->unique(['route_id', 'app_instance_id']);
        });

        DB::statement(<<<'SQL'
            CREATE TRIGGER routes_contract_insert
            BEFORE INSERT ON routes
            WHEN (
                (NEW.node_id IS NULL) = (NEW.cluster_id IS NULL)
                OR (NEW.provenance = 'generated' AND NEW.generation_basis_node_id IS NULL)
                OR (NEW.provenance = 'explicit' AND NEW.generation_basis_node_id IS NOT NULL)
                OR NEW.status <> 'pending'
                OR NEW.failed_step IS NOT NULL
                OR NEW.error_code IS NOT NULL
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
                OR NEW.status <> 'pending'
                OR NEW.failed_step IS NOT NULL
                OR NEW.error_code IS NOT NULL
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
                OR (SELECT status FROM app_instances WHERE id = NEW.app_instance_id) <> 'active'
                OR (SELECT app_id FROM app_instances WHERE id = NEW.app_instance_id)
                    <> (SELECT app_id FROM routes WHERE id = NEW.route_id)
                OR (
                    (SELECT node_id FROM routes WHERE id = NEW.route_id) IS NOT NULL
                    AND (SELECT node_id FROM routes WHERE id = NEW.route_id)
                        <> (SELECT node_id FROM app_instances WHERE id = NEW.app_instance_id)
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
                OR (SELECT status FROM app_instances WHERE id = NEW.app_instance_id) <> 'active'
                OR (SELECT app_id FROM app_instances WHERE id = NEW.app_instance_id)
                    <> (SELECT app_id FROM routes WHERE id = NEW.route_id)
                OR (
                    (SELECT node_id FROM routes WHERE id = NEW.route_id) IS NOT NULL
                    AND (SELECT node_id FROM routes WHERE id = NEW.route_id)
                        <> (SELECT node_id FROM app_instances WHERE id = NEW.app_instance_id)
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
                    AND EXISTS (
                        SELECT 1 FROM route_targets
                        WHERE route_id = NEW.route_id AND id <> OLD.id
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
        DB::statement('DROP TRIGGER IF EXISTS route_targets_contract_update');
        DB::statement('DROP TRIGGER IF EXISTS route_targets_contract_insert');
        DB::statement('DROP TRIGGER IF EXISTS routes_contract_update');
        DB::statement('DROP TRIGGER IF EXISTS routes_contract_insert');
        Schema::dropIfExists('route_targets');
        Schema::dropIfExists('routes');
    }
};
