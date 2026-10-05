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
        foreach (['routes', 'route_targets', 'instance_environment_values', 'processes', 'process_definitions'] as $name) {
            Schema::table($name, static function (Blueprint $table): void {
                $table->string('app', 63)->nullable();
            });
        }
        DB::table('routes')->where('kind', 'app')->update(['app' => 'web']);
        DB::table('route_targets')->update(['app' => 'web']);
        DB::table('instance_environment_values')->update(['app' => 'web']);
        DB::table('processes')->where('owner_type', 'instance')->update(['app' => 'web']);
        DB::table('process_definitions')->update(['app' => 'web']);
        foreach (DB::table('processes')->where('owner_type', 'instance')->get(['id', 'owner_id', 'runtime_config']) as $process) {
            if (! is_string($process->runtime_config)) {
                throw new RuntimeException('Unreadable legacy Process runtime ownership.');
            }
            $config = json_decode($process->runtime_config, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($config) || ($config['preset'] ?? null) !== 'antigravity-watch') {
                continue;
            }
            $parents = DB::table('processes')->where('owner_type', 'instance')->where('owner_id', $process->owner_id)->where('runtime_config->preset', 'agentation-mcp')->get(['id']);
            if ($parents->count() !== 1) {
                throw new RuntimeException('A legacy watcher has no unique owned HTTP Process.');
            }
            $config['agentation_process_id'] = $parents->sole()->id;
            DB::table('processes')->where('id', $process->id)->update(['runtime_config' => json_encode($config, JSON_THROW_ON_ERROR)]);
        }
        Schema::table('instances', static function (Blueprint $table): void {
            $table->json('app_runtime')->nullable();
        });
        foreach (DB::table('instances')->get() as $instance) {
            DB::table('instances')->where('id', $instance->id)->update([
                'app_runtime' => json_encode(['web' => [
                    'app_identity' => false,
                    'annotator_store_identity' => false,
                    'php_version' => $instance->selected_php_version,
                    'laravel' => $instance->source_is_laravel === null ? null : (bool) $instance->source_is_laravel,
                    'vite_port' => $instance->vite_port,
                    'agentation_port' => $instance->agentation_port,
                    'annotator_port' => $instance->annotator_port,
                ]], JSON_THROW_ON_ERROR),
            ]);
        }
        Schema::table('instance_environment_values', static function (Blueprint $table): void {
            $table->dropUnique(['instance_id', 'env_key']);
            $table->unique(['instance_id', 'app', 'env_key']);
        });
        foreach (['vite_port_assignments', 'annotation_port_assignments'] as $name) {
            Schema::table($name, static function (Blueprint $table) use ($name): void {
                $table->dropPrimary();
                $table->string('app', 63)->default('web');
                $table->primary($name === 'vite_port_assignments' ? ['instance_id', 'node_id', 'app'] : ['instance_id', 'node_id', 'app', 'kind']);
            });
        }
        Schema::table('instance_removal_members', static function (Blueprint $table): void {
            $table->json('route_ids')->nullable();
        });
        foreach (DB::table('instance_removal_members')->get(['id', 'route_id']) as $member) {
            DB::table('instance_removal_members')->where('id', $member->id)->update(['route_ids' => json_encode($member->route_id === null ? [] : [$member->route_id], JSON_THROW_ON_ERROR)]);
        }
        Schema::create('app_runtime_migrations', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->enum('phase', ['reserved', 'prepared', 'activating', 'rollback_required', 'published', 'complete']);
            $table->json('plan');
            $table->json('observations')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX app_runtime_migrations_open_node ON app_runtime_migrations(node_id) WHERE phase <> 'complete'");
        DB::statement(<<<'SQL'
            CREATE TRIGGER app_runtime_migrations_identity_update
            BEFORE UPDATE ON app_runtime_migrations
            WHEN NEW.id IS NOT OLD.id OR NEW.node_id IS NOT OLD.node_id OR NEW.plan IS NOT OLD.plan
                OR (OLD.published_at IS NOT NULL AND NEW.published_at IS NOT OLD.published_at)
                OR (OLD.published_at IS NOT NULL AND NEW.phase NOT IN ('published', 'complete'))
            BEGIN SELECT RAISE(ABORT, 'Runtime migration ownership is immutable.'); END
            SQL);
        $this->scopeRemovalGuards();
        $this->scopeTargetGuards();
    }

    private function scopeRemovalGuards(): void
    {
        $name = "json_extract(projects.apps, '$[0].name')";
        $path = "COALESCE(json_extract(instances.app_overrides, '$.' || {$name} || '.path'), json_extract(projects.apps, '$[0].path'))";
        $webRoot = "CASE WHEN json_type(instances.app_overrides, '$.' || {$name}) IS NOT NULL THEN json_extract(instances.app_overrides, '$.' || {$name} || '.web_root') ELSE json_extract(projects.apps, '$[0].web_root') END";
        $root = "CASE WHEN NEW.environment = 'production' THEN COALESCE(instances.root, projects.root) WHEN json_array_length(projects.apps) > 1 THEN '.' WHEN ({$webRoot}) IS NULL THEN {$path} WHEN ({$path}) = '.' THEN ({$webRoot}) ELSE ({$path}) || '/' || ({$webRoot}) END";
        foreach (DB::table('sqlite_master')->where('type', 'trigger')->get(['name', 'sql']) as $trigger) {
            $sql = $trigger->sql;
            if (! is_string($sql) || ! is_string($trigger->name)) {
                throw new RuntimeException('Unreadable removal guard.');
            }
            $updated = str_replace([
                'instance_removal_members.route_id = OLD.route_id',
                'instance_removal_members.route_id = OLD.id',
                'instance_removal_members.route_id = NEW.route_id',
            ], [
                '(instance_removal_members.route_id = OLD.route_id OR OLD.route_id IN (SELECT value FROM json_each(instance_removal_members.route_ids)))',
                '(instance_removal_members.route_id = OLD.id OR OLD.id IN (SELECT value FROM json_each(instance_removal_members.route_ids)))',
                '(instance_removal_members.route_id = NEW.route_id OR NEW.route_id IN (SELECT value FROM json_each(instance_removal_members.route_ids)))',
            ], $sql);
            if (in_array($trigger->name, ['instance_removal_members_insert', 'instance_removal_members_update', 'instance_removal_members_immutable'], true)) {
                $updated = str_replace('COALESCE(instances.root, projects.root) IS NEW.root', "({$root}) IS NEW.root", $updated);
                $inventory = "COALESCE(NEW.route_ids, CASE WHEN NEW.route_id IS NULL THEN '[]' ELSE json_array(NEW.route_id) END)";
                $updated = str_replace('WHERE route_targets.route_id = NEW.route_id', "WHERE route_targets.route_id IN (SELECT value FROM json_each({$inventory}))", $updated);
                $updated = preg_replace_callback('/NEW\\.route_id IS (NOT )?NULL/', static fn (array $match): string => 'json_array_length('.$inventory.') '.(isset($match[1]) ? '> 0' : '= 0'), $updated) ?? throw new RuntimeException('Unreadable removal inventory guard.');
                $updated = str_replace('OR NEW.route_id IS NOT OLD.route_id', 'OR NEW.route_id IS NOT OLD.route_id OR NEW.route_ids IS NOT OLD.route_ids', $updated);
            }
            if ($updated !== $sql) {
                DB::statement('DROP TRIGGER "'.str_replace('"', '""', $trigger->name).'"');
                DB::statement($updated);
            }
        }
    }

    private function scopeTargetGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER instance_removal_app_inventory_insert
            BEFORE INSERT ON instance_removal_members
            WHEN NEW.route_ids IS NOT NULL AND (
                NOT json_valid(NEW.route_ids) OR json_type(NEW.route_ids) <> 'array'
                OR json_array_length(NEW.route_ids) <> (SELECT COUNT(DISTINCT value) FROM json_each(NEW.route_ids))
                OR (NEW.route_id IS NOT NULL AND NEW.route_id NOT IN (SELECT value FROM json_each(NEW.route_ids)))
                OR EXISTS (
                    SELECT 1 FROM json_each(NEW.route_ids) AS inventory
                    WHERE inventory.type <> 'integer' OR inventory.value <= 0 OR NOT EXISTS (
                        SELECT 1 FROM route_targets JOIN routes ON routes.id = route_targets.route_id
                        WHERE routes.id = inventory.value AND routes.project_id = NEW.project_id
                            AND route_targets.instance_id = NEW.instance_id
                            AND route_targets.app IS routes.app
                    )
                )
                OR EXISTS (
                    SELECT 1 FROM route_targets WHERE instance_id = NEW.instance_id
                        AND route_id NOT IN (SELECT value FROM json_each(NEW.route_ids))
                )
            )
            BEGIN SELECT RAISE(ABORT, 'Invalid frozen app Route inventory.'); END;
            CREATE TRIGGER instance_removal_app_inventory_update
            BEFORE UPDATE ON instance_removal_members
            WHEN NEW.route_ids IS NOT OLD.route_ids
            BEGIN SELECT RAISE(ABORT, 'Frozen app Route inventory is immutable.'); END;
            SQL);

        foreach (['route_targets_contract_insert', 'route_targets_contract_update'] as $name) {
            $sql = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->value('sql');
            if (! is_string($sql)) {
                throw new RuntimeException('Missing Route target ownership guard.');
            }
            $sql = str_replace(
                ['WHERE instance_id = NEW.instance_id', 'WHERE existing.instance_id = NEW.instance_id'],
                ['WHERE instance_id = NEW.instance_id AND app = NEW.app', 'WHERE existing.instance_id = NEW.instance_id AND existing.app = NEW.app'],
                $sql,
            );
            $sql = str_replace('NEW.position < 0', "((SELECT kind FROM routes WHERE id = NEW.route_id) = 'app' AND (NEW.app IS NULL OR NEW.app <> (SELECT app FROM routes WHERE id = NEW.route_id))) OR NEW.position < 0", $sql);
            DB::statement("DROP TRIGGER {$name}");
            DB::statement($sql);
        }
        $name = 'instances_active_route_update';
        DB::statement("DROP TRIGGER IF EXISTS {$name}");
        DB::statement(<<<'SQL'
            CREATE TRIGGER instances_active_route_update
            BEFORE UPDATE OF status ON instances
            WHEN NEW.status = 'active' AND NEW.task_workspace_routed IS NOT 0
                AND EXISTS (SELECT 1 FROM node_roles WHERE node_id = NEW.node_id AND role = 'app-dev' AND status IN ('active', 'removing'))
                AND EXISTS (
                SELECT 1 FROM json_each((SELECT apps FROM projects WHERE id = NEW.project_id)) AS configured
                WHERE NOT (json_extract(configured.value, '$.type') IN ('laravel-package', 'node-package')
                    AND COALESCE(json_extract(NEW.app_overrides, '$.' || json_extract(configured.value, '$.name') || '.path'), json_extract(configured.value, '$.path')) = '.'
                    AND (CASE WHEN json_type(NEW.app_overrides, '$.' || json_extract(configured.value, '$.name')) IS NOT NULL
                        THEN json_extract(NEW.app_overrides, '$.' || json_extract(configured.value, '$.name') || '.web_root')
                        ELSE json_extract(configured.value, '$.web_root') END) IS NULL)
                AND (SELECT COUNT(*) FROM route_targets JOIN routes ON routes.id = route_targets.route_id
                    WHERE route_targets.instance_id = NEW.id AND routes.app = json_extract(configured.value, '$.name')
                        AND routes.status IN ('active', 'activating')) <> 1
            )
            BEGIN
                SELECT RAISE(ABORT, 'An active Instance requires every app Route.');
            END
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('App runtime ownership cannot be discarded after publication.');
    }
};
