<?php

declare(strict_types=1);

use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classifies open assistance requests. A blocked implementer or reviewer is direction
 * and keeps its question. Every other open request is failure. The task row takes the
 * asking subtask, and a failure does not replace an open direction request.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The merged tasks table is the one these columns belong to. A historical migrate that
        // stops before that merge still has the old subtask table, which has no parent_id.
        if (! Schema::hasColumn('tasks', 'parent_id')) {
            return;
        }

        // SQLite runs each added column as its own ALTER TABLE, and this migration is not wrapped in a
        // transaction. A retry must add whichever column is still missing.
        if (! Schema::hasColumn('tasks', 'assistance_kind')) {
            Schema::table('tasks', static function (Blueprint $table): void {
                $table->string('assistance_kind')->nullable();
            });
        }
        if (! Schema::hasColumn('tasks', 'assistance_question')) {
            Schema::table('tasks', static function (Blueprint $table): void {
                $table->text('assistance_question')->nullable();
            });
        }

        // SQLite does not wrap this migration in a transaction. Classifying only rows that are still
        // unset lets a failed backfill continue on the next attempt without replacing a direction.
        $this->classifyOpenRequests();
    }

    private function classifyOpenRequests(): void
    {
        $subtasks = DB::table('tasks')
            ->whereNotNull('parent_id')
            ->where('assistance_requested', true)
            ->whereNull('assistance_kind')
            ->orderBy('parent_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'assistance_reason']);

        foreach ($subtasks as $subtask) {
            $reason = is_string($subtask->assistance_reason) ? $subtask->assistance_reason : '';
            $direction = TaskAssistance::isBlockedReason($reason);
            DB::table('tasks')->where('id', $subtask->id)->update([
                'assistance_kind' => $direction ? AssistanceKind::Direction->value : AssistanceKind::Failure->value,
                'assistance_question' => $direction ? TaskAssistance::questionFromBlockedReason($reason) : null,
            ]);
        }

        $directionByTask = [];
        $directions = DB::table('tasks')
            ->whereNotNull('parent_id')
            ->where('assistance_requested', true)
            ->where('assistance_kind', AssistanceKind::Direction->value)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['parent_id', 'assistance_question']);

        foreach ($directions as $subtask) {
            $parentId = (int) $subtask->parent_id;
            if (! array_key_exists($parentId, $directionByTask)) {
                $directionByTask[$parentId] = is_string($subtask->assistance_question) ? $subtask->assistance_question : null;
            }
        }

        $tasks = DB::table('tasks')
            ->whereNull('parent_id')
            ->where('assistance_requested', true)
            ->orderBy('id')
            ->get(['id', 'assistance_kind']);

        foreach ($tasks as $task) {
            $taskId = (int) $task->id;
            $current = is_string($task->assistance_kind) ? $task->assistance_kind : null;
            if (array_key_exists($taskId, $directionByTask)) {
                if ($current === AssistanceKind::Direction->value) {
                    continue;
                }
                DB::table('tasks')->where('id', $taskId)->update([
                    'assistance_kind' => AssistanceKind::Direction->value,
                    'assistance_question' => $directionByTask[$taskId],
                ]);

                continue;
            }
            if ($current !== null) {
                continue;
            }
            DB::table('tasks')->where('id', $taskId)->update([
                'assistance_kind' => AssistanceKind::Failure->value,
                'assistance_question' => null,
            ]);
        }

        $this->recordDirectionQuestions();
    }

    /**
     * One escalated question, with no cause, for each open direction subtask.
     * The rows do not store the original ask time, so both timestamps are this run.
     */
    private function recordDirectionQuestions(): void
    {
        if (! Schema::hasTable('task_questions')) {
            return;
        }

        $askedAt = now();
        $subtasks = DB::table('tasks')
            ->whereNotNull('parent_id')
            ->where('assistance_requested', true)
            ->where('assistance_kind', AssistanceKind::Direction->value)
            ->orderBy('id')
            ->get(['id', 'parent_id', 'assistance_reason', 'assistance_question', 'completion_attempt', 'review_attempt']);

        foreach ($subtasks as $subtask) {
            $exists = DB::table('task_questions')->where('subtask_id', $subtask->id)->exists();
            if ($exists) {
                continue;
            }

            $reason = is_string($subtask->assistance_reason) ? $subtask->assistance_reason : '';
            $reviewer = str_starts_with($reason, TaskAssistance::ReviewerBlockedPrefix);
            DB::table('task_questions')->insert([
                'task_id' => (int) $subtask->parent_id,
                'subtask_id' => (int) $subtask->id,
                'attempt' => max(1, $reviewer ? (int) $subtask->review_attempt : (int) $subtask->completion_attempt),
                'asked_by' => $reviewer ? 'reviewer' : 'implementer',
                'question' => is_string($subtask->assistance_question) ? $subtask->assistance_question : '',
                'status' => 'escalated',
                'answered_by' => null,
                'answer' => null,
                'cause' => null,
                'asked_at' => $askedAt,
                'escalated_at' => $askedAt,
                'answered_at' => null,
                'opened_comment_id' => null,
                'resolution_comment_id' => null,
                'answered_comment_id' => null,
                'created_at' => $askedAt,
                'updated_at' => $askedAt,
            ]);
        }

        $this->storeQuestionCounts();
    }

    /**
     * Fills questions and escalations when those columns already exist.
     * The following migration adds the columns and fills them when this file runs first.
     */
    private function storeQuestionCounts(): void
    {
        if (! Schema::hasColumn('tasks', 'questions') || ! Schema::hasColumn('tasks', 'parent_id')) {
            return;
        }

        foreach (DB::table('tasks')->whereNotNull('parent_id')->pluck('id') as $subtaskId) {
            DB::table('tasks')->where('id', $subtaskId)->update([
                'questions' => (int) DB::table('task_questions')->where('subtask_id', $subtaskId)->count(),
                'escalations' => (int) DB::table('task_questions')->where('subtask_id', $subtaskId)->whereNotNull('escalated_at')->count(),
            ]);
        }

        foreach (DB::table('tasks')->whereNull('parent_id')->pluck('id') as $parentId) {
            $row = DB::table('tasks')->where('parent_id', $parentId)
                ->selectRaw('coalesce(sum(questions), 0) as questions, coalesce(sum(escalations), 0) as escalations')
                ->first();
            DB::table('tasks')->where('id', $parentId)->update([
                'questions' => (int) ($row->questions ?? 0),
                'escalations' => (int) ($row->escalations ?? 0),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['assistance_question', 'assistance_kind'] as $column) {
            if (! Schema::hasColumn('tasks', $column)) {
                continue;
            }

            Schema::table('tasks', static function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }
};
