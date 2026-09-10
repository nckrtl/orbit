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
        $duplicates = DB::table('app_instances')
            ->select(['app_id', 'node_id'])
            ->where('environment', 'production')
            ->groupBy(['app_id', 'node_id'])
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('app_id')
            ->orderBy('node_id')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Production AppInstance placement must be unique per App and Node.');
        }

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->string('production_user', 32)->nullable()->after('checkout_path');
            $table->text('production_home')->nullable()->after('production_user');
        });

        $this->createProductionPlacementTriggers();
    }

    public function down(): void
    {
        if (DB::table('app_instances')->whereNotNull('production_user')->orWhereNotNull('production_home')->exists()) {
            throw new RuntimeException('Cannot discard recorded production AppInstance identity.');
        }

        $this->dropProductionPlacementTriggers();
        DB::statement('DROP INDEX IF EXISTS app_instances_production_placement_unique');

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->dropColumn(['production_user', 'production_home']);
        });
    }

    private function createProductionPlacementTriggers(): void
    {
        $this->dropProductionPlacementTriggers();
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instances_production_placement_insert
            BEFORE INSERT ON app_instances
            WHEN NEW.environment = 'production'
                AND EXISTS (
                    SELECT 1
                    FROM app_instances
                    WHERE app_id = NEW.app_id
                        AND node_id = NEW.node_id
                        AND environment = 'production'
                )
            BEGIN
                SELECT RAISE(ABORT, 'Production AppInstance placement already exists.');
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instances_production_placement_update
            BEFORE UPDATE OF app_id, node_id, environment ON app_instances
            WHEN NEW.environment = 'production'
                AND EXISTS (
                    SELECT 1
                    FROM app_instances
                    WHERE id <> OLD.id
                        AND app_id = NEW.app_id
                        AND node_id = NEW.node_id
                        AND environment = 'production'
                )
            BEGIN
                SELECT RAISE(ABORT, 'Production AppInstance placement already exists.');
            END
            SQL);
    }

    private function dropProductionPlacementTriggers(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS app_instances_production_placement_insert');
        DB::statement('DROP TRIGGER IF EXISTS app_instances_production_placement_update');
    }
};
