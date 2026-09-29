<?php

declare(strict_types=1);

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One tasks table holds a top-level task and its subtasks.
 * A top-level row keeps its task group id. A subtask gets a new id above those ids.
 * Threads, comments, checks, decisions, annotations, continuations, and activity subjects follow the new id.
 * Activity for a task group becomes activity for the top-level task with the same id.
 */
return new class extends Migration
{
    private const string TaskType = 'App\\Models\\Task';

    private const string TaskGroupType = 'App\\Models\\TaskGroup';

    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('task_groups') || ! Schema::hasTable('tasks')) {
            throw new RuntimeException('Tasks cannot be merged because task_groups or tasks is missing. Restore a database backup.');
        }

        if (Schema::hasColumn('tasks', 'parent_id')) {
            throw new RuntimeException('Tasks are already merged.');
        }

        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function (): void {
                $this->createMergedTable();
                $this->assertColumnsPreserved();
                $map = $this->subtaskIds();
                $this->copyGroups();
                $this->copySubtasks($map);
                $this->remapReferences($map);
                Schema::drop('tasks');
                Schema::rename('tasks_merged', 'tasks');
                $this->addIndexes();
                $this->addTaskForeignKeys();
                $this->pointGroupReferencesAtTasks();
                Schema::drop('task_groups');
                $this->syncSequence();
                $this->assertForeignKeys();
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The one task model migration cannot be rolled back. Restore a database backup.');
    }

    private function createMergedTable(): void
    {
        Schema::create('tasks_merged', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('taskable_type')->nullable();
            $table->unsignedBigInteger('taskable_id')->nullable();
            $table->string('title');
            $table->text('brief');
            $table->string('status')->default('todo');
            $table->unsignedBigInteger('position')->nullable();
            $table->string('pr_url')->nullable();
            $table->boolean('notify_coder')->nullable();
            $table->string('implementer_model')->nullable();
            $table->string('reviewer_model')->nullable();
            $table->unsignedBigInteger('tokens')->nullable();
            $table->integer('line_diff')->nullable();
            $table->unsignedBigInteger('lines_added')->nullable();
            $table->unsignedBigInteger('lines_deleted')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('reviewer_agent_thread_id')->nullable();
            $table->unsignedBigInteger('implementer_agent_thread_id')->nullable();
            $table->string('execution_mode')->nullable();
            $table->boolean('assistance_requested')->default(false);
            $table->text('assistance_reason')->nullable();
            $table->string('implementer_agent_driver')->nullable();
            $table->string('reviewer_agent_driver')->nullable();
            $table->timestamp('agent_unavailable_since')->nullable();
            $table->timestamp('agent_unavailable_notified_at')->nullable();
            $table->string('type')->nullable();
            $table->string('target_thread_id', 128)->nullable();
            $table->text('completion_summary')->nullable();
            $table->string('subtask_start_commit')->nullable();
            $table->unsignedInteger('completion_attempt')->nullable();
            $table->unsignedBigInteger('completion_handoff_comment_id')->nullable();
            $table->unsignedInteger('completion_reminder_attempt')->nullable();
            $table->string('completion_reminder_input_id')->nullable();
            $table->unsignedInteger('completion_handoff_attempt')->nullable();
            $table->string('completion_handoff_turn_id')->nullable();
            $table->unsignedInteger('review_attempt')->nullable();
            $table->unsignedBigInteger('review_handled_comment_id')->nullable();
            $table->unsignedInteger('review_reminder_attempt')->nullable();
            $table->string('review_reminder_input_id')->nullable();
            $table->unsignedInteger('review_notified_attempt')->nullable();
            $table->string('review_notified_turn_id')->nullable();
            $table->string('review_workspace_head')->nullable();
            $table->string('review_workspace_tree')->nullable();
            $table->json('deliverables')->nullable();
            $table->text('fixup_problem')->nullable();
            $table->string('fixup_head_sha')->nullable();
            $table->unsignedInteger('communication_failures')->nullable();
            $table->unsignedBigInteger('resolution_delivered_comment_id')->nullable();
            $table->unsignedInteger('pi_restart_resumes')->nullable();
            $table->string('pi_restart_key')->nullable();
            $table->unsignedBigInteger('pi_restart_thread_id')->nullable();
            $table->string('pi_restart_source_turn_id')->nullable();
            $table->string('pi_restart_reservation')->nullable();
            $table->string('pi_restart_session_revision')->nullable();
            $table->unsignedBigInteger('continuation_of_task_id')->nullable();
        });
    }

    private function addIndexes(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->unique(['parent_id', 'position'], 'tasks_parent_id_position_unique');
            $table->index(['project_id', 'status'], 'tasks_project_id_status_index');
            $table->index('status', 'tasks_status_index');
            $table->index('execution_mode', 'tasks_execution_mode_index');
            $table->index('target_thread_id', 'tasks_target_thread_id_index');
            $table->index(['taskable_type', 'taskable_id'], 'tasks_taskable_type_taskable_id_index');
        });
    }

    private function assertColumnsPreserved(): void
    {
        $expected = array_values(array_unique(array_merge(
            array_diff(Schema::getColumnListing('task_groups'), ['id']),
            array_diff(Schema::getColumnListing('tasks'), ['id', 'task_group_id']),
            ['id', 'parent_id'],
        )));
        $missing = array_diff($expected, Schema::getColumnListing('tasks_merged'));

        if ($missing !== []) {
            throw new RuntimeException('Merged tasks table is missing: '.implode(', ', $missing));
        }
    }

    /** @return array<int, int> */
    private function subtaskIds(): array
    {
        $next = ($this->intOrNull(DB::table('task_groups')->max('id')) ?? 0) + 1;
        $map = [];

        foreach (DB::table('tasks')->orderBy('id')->pluck('id') as $id) {
            $map[$this->intValue($id)] = $next;
            $next++;
        }

        return $map;
    }

    private function copyGroups(): void
    {
        $columns = array_diff(Schema::getColumnListing('task_groups'), ['id']);
        $merged = Schema::getColumnListing('tasks_merged');

        foreach (DB::table('task_groups')->orderBy('id')->get() as $group) {
            $row = array_fill_keys($merged, null);
            $row['id'] = $group->id;
            $row['parent_id'] = null;

            foreach ($columns as $column) {
                $row[$column] = $group->{$column};
            }

            DB::table('tasks_merged')->insert($row);
        }
    }

    /** @param  array<int, int>  $map */
    private function copySubtasks(array $map): void
    {
        $columns = array_diff(Schema::getColumnListing('tasks'), ['id', 'task_group_id']);
        $merged = Schema::getColumnListing('tasks_merged');

        foreach (DB::table('tasks')->orderBy('id')->get() as $subtask) {
            $oldId = $this->intValue($subtask->id);
            $row = array_fill_keys($merged, null);
            $row['id'] = $map[$oldId];
            $row['parent_id'] = $this->intValue($subtask->task_group_id);

            foreach ($columns as $column) {
                $row[$column] = $subtask->{$column};
            }

            if ($subtask->continuation_of_task_id !== null) {
                $source = $this->intValue($subtask->continuation_of_task_id);

                if (! isset($map[$source])) {
                    throw new RuntimeException("Subtask {$oldId} continues {$source}, which is not a subtask.");
                }

                $row['continuation_of_task_id'] = $map[$source];
            }

            DB::table('tasks_merged')->insert($row);
        }
    }

    /** @param  array<int, int>  $map */
    private function remapReferences(array $map): void
    {
        foreach ([
            ['agent_threads', 'task_id'],
            ['task_comments', 'task_id'],
            ['task_checks', 'task_id'],
            ['annotations', 'task_id'],
            ['jev_decisions', 'task_id'],
        ] as [$table, $column]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                $this->remapIntegerColumn($table, $column, $map);
            }
        }

        if (Schema::hasTable('jev_decisions') && Schema::hasColumn('jev_decisions', 'task_ids')) {
            $this->remapDecisionTaskIds($map);
        }

        $this->remapActivitySubjects($map);
    }

    /** @param  array<int, int>  $map */
    private function remapActivitySubjects(array $map): void
    {
        if (! Schema::hasTable('activity_log') || ! Schema::hasColumn('activity_log', 'subject_type') || ! Schema::hasColumn('activity_log', 'subject_id')) {
            return;
        }

        if ($map !== []) {
            foreach ($map as $old => $new) {
                DB::table('activity_log')->where('subject_type', self::TaskType)->where('subject_id', $old)->update(['subject_id' => -$new]);
            }

            foreach (array_chunk(array_map(static fn (int $new): int => -$new, array_values($map)), 500) as $chunk) {
                DB::table('activity_log')->where('subject_type', self::TaskType)->whereIn('subject_id', $chunk)->update([
                    'subject_id' => $this->negatedColumn('subject_id'),
                ]);
            }
        }

        DB::table('activity_log')->where('subject_type', self::TaskGroupType)->update(['subject_type' => self::TaskType]);
    }

    /** @param  array<int, int>  $map */
    private function remapIntegerColumn(string $table, string $column, array $map): void
    {
        if ($map === []) {
            return;
        }

        foreach ($map as $old => $new) {
            DB::table($table)->where($column, $old)->update([$column => -$new]);
        }

        foreach (array_chunk(array_map(static fn (int $new): int => -$new, array_values($map)), 500) as $chunk) {
            DB::table($table)->whereIn($column, $chunk)->update([
                $column => $this->negatedColumn($column),
            ]);
        }
    }

    /** @param  array<int, int>  $map */
    private function remapDecisionTaskIds(array $map): void
    {
        foreach (DB::table('jev_decisions')->whereNotNull('task_ids')->orderBy('id')->get(['id', 'task_ids']) as $decision) {
            $decoded = json_decode((string) $decision->task_ids, true);

            if (! is_array($decoded)) {
                throw new RuntimeException("Jev decision {$decision->id} has a task_ids value that is not a list.");
            }

            $mapped = [];

            foreach ($decoded as $id) {
                if (! is_int($id) && ! is_string($id)) {
                    throw new RuntimeException("Jev decision {$decision->id} lists a task id that is not an integer.");
                }

                $old = (int) $id;

                if (! isset($map[$old])) {
                    throw new RuntimeException("Jev decision {$decision->id} lists task {$old}, which is not a subtask.");
                }

                $mapped[] = $map[$old];
            }

            DB::table('jev_decisions')->where('id', $decision->id)->update([
                'task_ids' => json_encode($mapped, JSON_THROW_ON_ERROR),
            ]);
        }
    }

    private function addTaskForeignKeys(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $table->foreign('reviewer_agent_thread_id')->references('id')->on('agent_threads')->nullOnDelete();
            $table->foreign('implementer_agent_thread_id')->references('id')->on('agent_threads')->nullOnDelete();
            $table->foreign('pi_restart_thread_id')->references('id')->on('agent_threads')->nullOnDelete();
            $table->foreign('completion_handoff_comment_id')->references('id')->on('task_comments')->nullOnDelete();
            $table->foreign('review_handled_comment_id')->references('id')->on('task_comments')->nullOnDelete();
            $table->foreign('resolution_delivered_comment_id')->references('id')->on('task_comments')->nullOnDelete();
            $table->foreign('continuation_of_task_id')->references('id')->on('tasks')->nullOnDelete();
        });
    }

    private function pointGroupReferencesAtTasks(): void
    {
        foreach ([
            'agent_threads' => 'cascade',
            'task_comments' => 'cascade',
            'jev_decisions' => 'null',
        ] as $table => $onDelete) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'task_group_id')) {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint) use ($onDelete): void {
                $blueprint->dropForeign(['task_group_id']);
                $foreign = $blueprint->foreign('task_group_id')->references('id')->on('tasks');

                if ($onDelete === 'cascade') {
                    $foreign->cascadeOnDelete();
                } else {
                    $foreign->nullOnDelete();
                }
            });
        }
    }

    private function syncSequence(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite' || ! Schema::hasTable('sqlite_sequence')) {
            return;
        }

        $max = $this->intOrNull(DB::table('tasks')->max('id')) ?? 0;
        $updated = DB::table('sqlite_sequence')->where('name', 'tasks')->update(['seq' => $max]);

        if ($updated === 0) {
            DB::table('sqlite_sequence')->insert(['name' => 'tasks', 'seq' => $max]);
        }
    }

    private function assertForeignKeys(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $violations = DB::select('pragma foreign_key_check');

        if ($violations !== []) {
            throw new RuntimeException('Merging tasks left foreign key violations.');
        }
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new RuntimeException('Expected an integer task id.');
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : $this->intValue($value);
    }

    private function negatedColumn(string $column): Expression
    {
        return match ($column) {
            'task_id' => DB::raw('-task_id'),
            'subject_id' => DB::raw('-subject_id'),
            default => throw new RuntimeException("Cannot remap {$column}."),
        };
    }
};
