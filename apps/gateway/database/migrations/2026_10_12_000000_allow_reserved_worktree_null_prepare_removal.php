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
        if (DB::table('instance_removal_members')
            ->where('source_layout', 'worktree')
            ->whereNull('starting_commit')
            ->exists()) {
            throw new RuntimeException('Cannot roll back unresolved task worktree removal evidence.');
        }

        $this->applyChanges(true);
    }

    private function applyChanges(bool $reverse): void
    {
        DB::transaction(function () use ($reverse): void {
            foreach ([
                ['instance_removals_insert', 'instances', 1],
                ['instance_removal_members_insert', 'instances', 2],
                ['instances_removal_status_update', 'OLD', 1],
            ] as [$trigger, $row, $count]) {
                $worktree = "({$row}.source_layout = 'worktree'"
                    ." AND {$row}.status = 'reserved' AND {$row}.starting_commit IS NULL"
                    ." AND {$row}.task_workspace_routed IS NOT NULL)";
                $before = "({$row}.source_layout = 'checkout' OR {$worktree})"
                    ." AND {$row}.source_prepare_id IS NOT NULL AND {$row}.registration_request_id IS NULL";
                $after = "(({$row}.source_layout = 'checkout' AND {$row}.source_prepare_id IS NOT NULL) OR {$worktree})"
                    ." AND {$row}.registration_request_id IS NULL";
                $this->replace($trigger, $reverse ? $after : $before, $reverse ? $before : $after, $count);
            }
        });
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
