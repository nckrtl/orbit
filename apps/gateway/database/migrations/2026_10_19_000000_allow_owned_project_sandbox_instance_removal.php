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
        if (DB::table('instance_removal_members')->where('source_identity', 'like', 'sandbox:%')->exists()) {
            throw new RuntimeException('Cannot roll back Project sandbox removal evidence.');
        }
        $this->applyChanges(true);
    }

    private function applyChanges(bool $reverse): void
    {
        DB::transaction(function () use ($reverse): void {
            $rootBefore = 'OR NEW.root IS NULL';
            $rootAfter = 'OR (NEW.root IS NULL AND NOT EXISTS (SELECT 1 FROM instances'
                .' WHERE instances.id = NEW.instance_id AND instances.task_workspace_routed = 0'
                ." AND NEW.source_identity = 'sandbox:' || instances.task_sandbox_id AND "
                .$this->ownedSandbox('instances').'))';
            $this->replace('instance_removal_members_insert', $reverse ? $rootAfter : $rootBefore,
                $reverse ? $rootBefore : $rootAfter, 1);
            foreach ([
                ['instance_removals_insert', 'instances', 1],
                ['instance_removal_members_insert', 'instances', 2],
                ['instances_removal_status_update', 'OLD', 1],
            ] as [$trigger, $row, $count]) {
                $before = "{$row}.failed_step IS NOT NULL AND {$row}.error_code IS NOT NULL";
                $after = '('.$before.' OR '.$this->ownedSandbox($row).')';
                $this->replace($trigger, $reverse ? $after : $before, $reverse ? $before : $after, $count);
            }
        });
    }

    private function ownedSandbox(string $row): string
    {
        return "({$row}.source_layout = 'checkout' AND {$row}.checkout_path = '/home/orbit/orbit'"
            ." AND {$row}.task_workspace_routed IS NOT NULL"
            .' AND EXISTS (SELECT 1 FROM task_sandboxes'
            .' JOIN tasks ON tasks.id = task_sandboxes.group_id'
            .' JOIN projects ON projects.id = tasks.project_id'
            .' JOIN nodes ON nodes.id = task_sandboxes.node_id'
            .' JOIN node_roles ON node_roles.node_id = nodes.id'
            ." WHERE task_sandboxes.id = {$row}.task_sandbox_id"
            ." AND task_sandboxes.node_id = {$row}.node_id AND nodes.compute_sandbox_id = task_sandboxes.id"
            ." AND task_sandboxes.provider IN ('incus', 'upcloud') AND task_sandboxes.enrollment IS NOT NULL"
            ." AND task_sandboxes.desired_power = 'destroyed' AND task_sandboxes.model_key IS NULL"
            ." AND tasks.parent_id IS NULL AND tasks.task_compute = 'vm' AND tasks.execution_mode = 'managed'"
            ." AND tasks.project_id = {$row}.project_id AND projects.slug <> 'orbit'"
            ." AND tasks.taskable_type = 'instance' AND tasks.taskable_id = {$row}.id"
            ." AND {$row}.name = 'task-' || tasks.id AND {$row}.branch_override = {$row}.name"
            ." AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing')))";
    }

    private function replace(string $trigger, string $clause, string $replacement, int $count): void
    {
        $tokens = preg_split('/\s+/', trim($clause), flags: PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            throw new RuntimeException('The legacy reserved worktree removal clause is invalid.');
        }
        $pattern = '/'.implode('\s+', array_map(static fn (string $token): string => preg_quote($token, '/'), $tokens)).'/';
        $sql = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger)->value('sql');
        if (! is_string($sql) || preg_match_all($pattern, $sql) !== $count) {
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
