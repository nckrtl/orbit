<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string PROD_ROLE = "EXISTS (SELECT 1 FROM node_roles AS production_role WHERE production_role.node_id = %s AND production_role.role = 'app-prod' AND production_role.status = 'active')";

    public function up(): void
    {
        DB::transaction(function (): void {
            /** @var list<object{id: int|string}> $unplaced */
            $unplaced = DB::select(<<<'SQL'
            SELECT app_instances.id
            FROM app_instances
            WHERE NOT EXISTS (
                SELECT 1
                FROM node_roles
                WHERE node_roles.node_id = app_instances.node_id
                    AND node_roles.role = CASE app_instances.environment
                        WHEN 'production' THEN 'app-prod'
                        WHEN 'development' THEN 'app-dev'
                    END
                    AND node_roles.status IN ('active', 'removing')
            )
            ORDER BY app_instances.id
            SQL);

            if ($unplaced !== []) {
                $ids = implode(', ', array_map(static fn (object $row): string => (string) $row->id, $unplaced));
                throw new RuntimeException("Cannot remove AppInstance environment without a usable matching Node role for Instances: {$ids}.");
            }

            $this->rewriteTriggersToNodeRoles();

            Schema::table('app_instances', static function (Blueprint $table): void {
                $table->dropColumn('environment');
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Schema::table('app_instances', static function (Blueprint $table): void {
                $table->string('environment')->default('development')->after('name');
            });

            DB::statement(<<<'SQL'
                UPDATE app_instances
                SET environment = CASE
                    WHEN EXISTS (
                        SELECT 1 FROM node_roles
                        WHERE node_roles.node_id = app_instances.node_id
                            AND node_roles.role = 'app-prod'
                            AND node_roles.status IN ('active', 'removing')
                    ) THEN 'production'
                    WHEN EXISTS (
                        SELECT 1 FROM node_roles
                        WHERE node_roles.node_id = app_instances.node_id
                            AND node_roles.role = 'app-dev'
                            AND node_roles.status IN ('active', 'removing')
                    ) THEN 'development'
                    ELSE 'development'
                END
                SQL);

            $this->rewriteTriggersToEnvironment();
        });
    }

    private function rewriteTriggersToNodeRoles(): void
    {
        /** @var list<object{name: string, sql: string}> $triggers */
        $triggers = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND name IN ('app_instances_production_placement_insert', 'app_instances_production_placement_update', 'routes_contract_update', 'route_targets_contract_insert', 'route_targets_contract_update', 'production_route_target_instances_update', 'app_instance_removal_members_insert')");

        foreach ($triggers as $trigger) {
            $sql = $trigger->sql;

            if (str_starts_with($trigger->name, 'app_instances_production_placement_')) {
                DB::statement('DROP TRIGGER IF EXISTS "'.str_replace('"', '""', $trigger->name).'"');

                continue;
            }

            if ($trigger->name === 'routes_contract_update') {
                $sql = str_replace(
                    "app_instances.environment <> 'production'",
                    'NOT '.sprintf(self::PROD_ROLE, 'app_instances.node_id'),
                    $sql,
                );
            }

            $sql = str_replace(
                "(SELECT environment FROM app_instances WHERE id = NEW.app_instance_id) <> 'production'",
                "NOT EXISTS (SELECT 1 FROM app_instances AS target_instance JOIN node_roles AS production_role ON production_role.node_id = target_instance.node_id WHERE target_instance.id = NEW.app_instance_id AND production_role.role = 'app-prod' AND production_role.status = 'active')",
                $sql,
            );
            $sql = str_replace("NEW.environment <> 'production'", 'NOT '.sprintf(self::PROD_ROLE, 'NEW.node_id'), $sql);
            $sql = str_replace(
                "existing_instance.environment <> 'production'",
                "NOT EXISTS (SELECT 1 FROM node_roles AS production_role WHERE production_role.node_id = existing_instance.node_id AND production_role.role = 'app-prod' AND production_role.status = 'active')",
                $sql,
            );
            $sql = str_replace('UPDATE OF app_id, node_id, environment ON app_instances', 'UPDATE OF app_id, node_id ON app_instances', $sql);

            if ($trigger->name === 'app_instance_removal_members_insert') {
                $sql = str_replace(
                    'AND app_instances.environment = NEW.environment',
                    "AND ((NEW.environment = 'production' AND EXISTS (SELECT 1 FROM node_roles AS removal_role WHERE removal_role.node_id = app_instances.node_id AND removal_role.role = 'app-prod' AND removal_role.status IN ('active', 'removing'))) OR (NEW.environment = 'development' AND EXISTS (SELECT 1 FROM node_roles AS removal_role WHERE removal_role.node_id = app_instances.node_id AND removal_role.role = 'app-dev' AND removal_role.status IN ('active', 'removing'))))",
                    $sql,
                );
            }

            if ($sql === $trigger->sql) {
                continue;
            }

            DB::statement('DROP TRIGGER IF EXISTS "'.str_replace('"', '""', $trigger->name).'"');
            DB::statement($sql);
        }

        $this->createProductionPlacementTriggers(true);

        /** @var list<object{name: string}> $remainingReferences */
        $remainingReferences = DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND (sql LIKE '%app_instances.environment%' OR sql LIKE '%environment FROM app_instances%' OR sql LIKE '%existing_instance.environment%' OR sql LIKE '%UPDATE OF app_id, node_id, environment ON app_instances%' OR sql LIKE '%NEW.environment <> ''production''%')");

        if ($remainingReferences !== []) {
            $names = implode(', ', array_map(static fn (object $trigger): string => $trigger->name, $remainingReferences));
            throw new RuntimeException("Cannot remove AppInstance environment while database triggers still read it: {$names}.");
        }
    }

    private function rewriteTriggersToEnvironment(): void
    {
        $this->createProductionPlacementTriggers(false);

        /** @var list<object{name: string, sql: string}> $triggers */
        $triggers = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND name IN ('app_instances_production_placement_insert', 'app_instances_production_placement_update', 'routes_contract_update', 'route_targets_contract_insert', 'route_targets_contract_update', 'production_route_target_instances_update', 'app_instance_removal_members_insert')");

        foreach ($triggers as $trigger) {
            if (str_starts_with($trigger->name, 'app_instances_production_placement_')) {
                continue;
            }

            $sql = $trigger->sql;
            $sql = str_replace(
                "NOT EXISTS (SELECT 1 FROM node_roles AS production_role WHERE production_role.node_id = app_instances.node_id AND production_role.role = 'app-prod' AND production_role.status = 'active')",
                "app_instances.environment <> 'production'",
                $sql,
            );
            $sql = str_replace(
                "NOT EXISTS (SELECT 1 FROM app_instances AS target_instance JOIN node_roles AS production_role ON production_role.node_id = target_instance.node_id WHERE target_instance.id = NEW.app_instance_id AND production_role.role = 'app-prod' AND production_role.status = 'active')",
                "(SELECT environment FROM app_instances WHERE id = NEW.app_instance_id) <> 'production'",
                $sql,
            );
            $sql = str_replace(
                "NOT EXISTS (SELECT 1 FROM node_roles AS production_role WHERE production_role.node_id = NEW.node_id AND production_role.role = 'app-prod' AND production_role.status = 'active')",
                "NEW.environment <> 'production'",
                $sql,
            );
            $sql = str_replace(
                "NOT EXISTS (SELECT 1 FROM node_roles AS production_role WHERE production_role.node_id = existing_instance.node_id AND production_role.role = 'app-prod' AND production_role.status = 'active')",
                "existing_instance.environment <> 'production'",
                $sql,
            );
            $sql = str_replace('UPDATE OF app_id, node_id ON app_instances', 'UPDATE OF app_id, node_id, environment ON app_instances', $sql);
            $sql = str_replace(
                "((NEW.environment = 'production' AND EXISTS (SELECT 1 FROM node_roles AS removal_role WHERE removal_role.node_id = app_instances.node_id AND removal_role.role = 'app-prod' AND removal_role.status IN ('active', 'removing'))) OR (NEW.environment = 'development' AND EXISTS (SELECT 1 FROM node_roles AS removal_role WHERE removal_role.node_id = app_instances.node_id AND removal_role.role = 'app-dev' AND removal_role.status IN ('active', 'removing'))))",
                'app_instances.environment = NEW.environment',
                $sql,
            );

            if ($sql === $trigger->sql) {
                continue;
            }

            DB::statement('DROP TRIGGER IF EXISTS "'.str_replace('"', '""', $trigger->name).'"');
            DB::statement($sql);
        }
    }

    private function createProductionPlacementTriggers(bool $nodeRoles): void
    {
        DB::statement('DROP TRIGGER IF EXISTS app_instances_production_placement_insert');
        DB::statement('DROP TRIGGER IF EXISTS app_instances_production_placement_update');

        if ($nodeRoles) {
            $currentPlacement = "EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = NEW.node_id AND node_roles.role = 'app-prod' AND node_roles.status IN ('active', 'removing'))";
            $existingPlacement = "EXISTS (SELECT 1 FROM node_roles AS existing_role WHERE existing_role.node_id = app_instances.node_id AND existing_role.role = 'app-prod' AND existing_role.status IN ('active', 'removing'))";
            $updateColumns = 'app_id, node_id';
            $currentCondition = $currentPlacement;
            $existingCondition = $existingPlacement;
        } else {
            $currentCondition = "NEW.environment = 'production'";
            $existingCondition = "environment = 'production'";
            $updateColumns = 'app_id, node_id, environment';
            $currentPlacement = $currentCondition;
            $existingPlacement = $existingCondition;
        }

        DB::statement("CREATE TRIGGER app_instances_production_placement_insert BEFORE INSERT ON app_instances WHEN {$currentCondition} AND EXISTS (SELECT 1 FROM app_instances WHERE app_id = NEW.app_id AND node_id = NEW.node_id AND {$existingCondition}) BEGIN SELECT RAISE(ABORT, 'Production AppInstance placement already exists.'); END");
        DB::statement("CREATE TRIGGER app_instances_production_placement_update BEFORE UPDATE OF {$updateColumns} ON app_instances WHEN {$currentCondition} AND EXISTS (SELECT 1 FROM app_instances WHERE id <> OLD.id AND app_id = NEW.app_id AND node_id = NEW.node_id AND {$existingCondition}) BEGIN SELECT RAISE(ABORT, 'Production AppInstance placement already exists.'); END");
    }
};
