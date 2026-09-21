<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectType;
use App\Domain\Projects\ProjectTypeClassifier;
use App\Models\AppInstanceEnvironmentValue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->string('type', 32)->default(ProjectType::LaravelApp->value)->after('slug');
        });

        $this->classifyExistingProjects();
        $this->rewriteInstanceMorphs();
        $this->normalizeAppProdLaravelMode();
        $this->replaceWebServingRouteTriggers();
    }

    public function down(): void
    {
        $this->restoreUnscopedRouteTriggers();

        foreach (['processes' => 'owner_type', 'schedules' => 'target_type'] as $table => $column) {
            if (Schema::hasTable($table)) {
                DB::table($table)
                    ->where($column, 'instance')
                    ->update([$column => 'App\\Models\\AppInstance']);
            }
        }

        if (Schema::hasTable('task_groups')) {
            DB::table('task_groups')
                ->where('taskable_type', 'instance')
                ->update(['taskable_type' => 'App\\Models\\AppInstance']);
        }

        if (Schema::hasTable('activity_log')) {
            foreach (['subject_type', 'causer_type'] as $column) {
                DB::table('activity_log')
                    ->where($column, 'instance')
                    ->update([$column => 'App\\Models\\AppInstance']);
            }
        }

        Schema::table('apps', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }

    private function classifyExistingProjects(): void
    {
        $classifier = new ProjectTypeClassifier;

        foreach (DB::table('apps')->orderBy('id')->get(['id', 'slug', 'repository_identity', 'root']) as $app) {
            $hasProductionPhp = DB::table('app_instances')
                ->where('app_id', $app->id)
                ->whereNotNull('production_php_service')
                ->exists();

            $type = $classifier->classify([
                'slug' => (string) $app->slug,
                'repository_identity' => (string) $app->repository_identity,
                'root' => is_string($app->root) ? $app->root : null,
                'has_production_php' => $hasProductionPhp,
            ]);

            DB::table('apps')->where('id', $app->id)->update(['type' => $type->value]);
        }
    }

    private function rewriteInstanceMorphs(): void
    {
        $replacements = [
            'App\\Models\\AppInstance' => 'instance',
            'App\\Models\\Instance' => 'instance',
        ];

        foreach (['processes' => 'owner_type', 'schedules' => 'target_type'] as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($replacements as $from => $to) {
                DB::table($table)->where($column, $from)->update([$column => $to]);
            }
        }

        if (Schema::hasTable('task_groups')) {
            foreach ($replacements as $from => $to) {
                DB::table('task_groups')->where('taskable_type', $from)->update(['taskable_type' => $to]);
            }
        }

        if (Schema::hasTable('activity_log')) {
            foreach (['subject_type', 'causer_type'] as $column) {
                foreach ($replacements as $from => $to) {
                    DB::table('activity_log')->where($column, $from)->update([$column => $to]);
                }
            }
        }
    }

    private function normalizeAppProdLaravelMode(): void
    {
        $instanceIds = DB::table('app_instances')
            ->join('node_roles', 'node_roles.node_id', '=', 'app_instances.node_id')
            ->where('node_roles.role', 'app-prod')
            ->where('node_roles.status', 'active')
            ->distinct()
            ->orderBy('app_instances.id')
            ->pluck('app_instances.id');

        foreach ($instanceIds as $instanceId) {
            foreach (['APP_ENV' => 'production', 'APP_DEBUG' => 'false'] as $key => $value) {
                AppInstanceEnvironmentValue::query()->updateOrCreate(
                    ['app_instance_id' => $instanceId, 'env_key' => $key],
                    ['env_value' => $value],
                );
            }
        }
    }

    private function replaceWebServingRouteTriggers(): void
    {
        foreach ([
            'app_instances_active_route_update',
            'route_targets_active_delete',
            'route_targets_active_update',
            'routes_active_target_delete',
        ] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }

        DB::statement(<<<'SQL'
            CREATE TRIGGER app_instances_active_route_update
            BEFORE UPDATE OF status ON app_instances
            WHEN NEW.status = 'active'
                AND (SELECT type FROM apps WHERE id = NEW.app_id) = 'laravel-app'
                AND (
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
                (SELECT type FROM apps WHERE id = (
                    SELECT app_id FROM app_instances WHERE id = OLD.app_instance_id
                )) = 'laravel-app'
                AND (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'active'
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
                        (SELECT type FROM apps WHERE id = (
                            SELECT app_id FROM app_instances WHERE id = OLD.app_instance_id
                        )) = 'laravel-app'
                        AND (SELECT status FROM app_instances WHERE id = OLD.app_instance_id) = 'active'
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
                JOIN apps ON apps.id = app_instances.app_id
                WHERE route_targets.route_id = OLD.id
                    AND (
                        (apps.type = 'laravel-app' AND app_instances.status = 'active' AND OLD.status IN ('active', 'activating'))
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
    }

    private function restoreUnscopedRouteTriggers(): void
    {
        foreach ([
            'app_instances_active_route_update',
            'route_targets_active_delete',
            'route_targets_active_update',
            'routes_active_target_delete',
        ] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
        }

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
    }
};
