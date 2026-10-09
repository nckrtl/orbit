<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->applyChanges(false);
    }

    public function down(): void
    {
        $this->applyChanges(true);
    }

    private function applyChanges(bool $reverse): void
    {
        $noTargets = 'NOT EXISTS ( SELECT 1 FROM route_targets WHERE route_targets.instance_id = instances.id )';
        $workspaceRoute = 'EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = instances.node_id'
            ." AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing'))"
            .' AND (SELECT COUNT(DISTINCT route_id) FROM route_targets WHERE instance_id = instances.id) = 1'
            .' AND EXISTS (SELECT 1 FROM routes JOIN route_targets ON route_targets.route_id = routes.id'
            .' WHERE route_targets.instance_id = instances.id AND routes.project_id = instances.project_id'
            ." AND routes.status IN ('pending', 'failed')"
            .' AND (SELECT COUNT(*) FROM route_targets AS targets WHERE targets.route_id = routes.id) = 1)';
        $changes = [
            ['instance_removals_insert', "status = 'source_resolved' AND {$noTargets}", "status = 'source_resolved' AND ({$noTargets} OR ({$workspaceRoute}))"],
            ['instance_removal_members_insert', "instances.status = 'source_resolved' AND NEW.route_id IS NULL", "instances.status = 'source_resolved' AND (NEW.route_id IS NULL OR (NEW.runtime_published = 0 AND {$workspaceRoute}))"],
        ];

        DB::transaction(function () use ($reverse, $changes): void {
            foreach ($reverse ? array_reverse($changes) : $changes as [$trigger, $before, $after]) {
                $this->replace($trigger, $reverse ? $after : $before, $reverse ? $before : $after);
            }
        });
    }

    private function replace(string $trigger, string $clause, string $replacement): void
    {
        $tokens = preg_split('/\s+/', trim($clause), flags: PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            throw new RuntimeException('The workspace removal clause is invalid.');
        }
        $pattern = '/'.implode('\s+', array_map(static fn (string $token): string => preg_quote($token, '/'), $tokens)).'/';
        $sql = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger)->value('sql');
        if (! is_string($sql) || preg_match_all($pattern, $sql) !== 1) {
            throw new RuntimeException("The {$trigger} trigger is incompatible.");
        }
        $updated = preg_replace_callback($pattern, static fn (): string => $replacement, $sql);
        if (! is_string($updated)) {
            throw new RuntimeException("The {$trigger} trigger cannot be updated.");
        }
        DB::statement("DROP TRIGGER {$trigger}");
        DB::statement($updated);
    }
};
