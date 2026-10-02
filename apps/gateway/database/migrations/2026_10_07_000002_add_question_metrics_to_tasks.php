<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settle metrics for questions, the relay marker, and the cause stored on a turn receipt.
 * Counts are filled from task_questions so a migrate that already wrote those rows is not left at zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Columns belong on the merged tasks table. A migrate that still has the old subtask
        // table, which has no parent_id, must leave that table alone.
        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'parent_id')) {
            if (! Schema::hasColumn('tasks', 'questions')) {
                Schema::table('tasks', static function (Blueprint $table): void {
                    $table->unsignedInteger('questions')->default(0);
                });
            }
            if (! Schema::hasColumn('tasks', 'escalations')) {
                Schema::table('tasks', static function (Blueprint $table): void {
                    $table->unsignedInteger('escalations')->default(0);
                });
            }
            if (! Schema::hasColumn('tasks', 'direction_relay_comment_id')) {
                Schema::table('tasks', static function (Blueprint $table): void {
                    $table->unsignedBigInteger('direction_relay_comment_id')->nullable();
                });
            }
            if (! Schema::hasColumn('tasks', 'direction_answer_key')) {
                Schema::table('tasks', static function (Blueprint $table): void {
                    $table->string('direction_answer_key')->nullable();
                });
            }
            if (! Schema::hasColumn('tasks', 'direction_answer_source_turn_id')) {
                Schema::table('tasks', static function (Blueprint $table): void {
                    $table->string('direction_answer_source_turn_id')->nullable();
                });
            }
        }

        if (Schema::hasTable('task_comments') && ! Schema::hasColumn('task_comments', 'cause')) {
            Schema::table('task_comments', static function (Blueprint $table): void {
                $table->string('cause')->nullable();
            });
        }

        self::storeQuestionCounts();
    }

    public function down(): void
    {
        if (Schema::hasTable('task_comments') && Schema::hasColumn('task_comments', 'cause')) {
            Schema::table('task_comments', static function (Blueprint $table): void {
                $table->dropColumn('cause');
            });
        }

        if (! Schema::hasTable('tasks')) {
            return;
        }

        foreach (['direction_answer_source_turn_id', 'direction_answer_key', 'direction_relay_comment_id', 'escalations', 'questions'] as $column) {
            if (! Schema::hasColumn('tasks', $column)) {
                continue;
            }

            Schema::table('tasks', static function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }

    public static function storeQuestionCounts(): void
    {
        if (! Schema::hasTable('task_questions') || ! Schema::hasColumn('tasks', 'questions') || ! Schema::hasColumn('tasks', 'parent_id')) {
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
};
