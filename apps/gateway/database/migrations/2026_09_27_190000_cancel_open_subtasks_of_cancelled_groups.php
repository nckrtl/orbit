<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Groups cancelled before cancel also closed their subtasks still hold todo and running rows.
 * Those rows are not completed, failed, or cancelled work, so they end as cancelled.
 * A settled time that is already stored stays; an empty one is filled. Assistance on those rows is cleared.
 * The previous status is not recoverable, and cancelled remains a valid end state.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $this->openSubtasks()->whereNull('settled_at')->update([
            'status' => 'cancelled',
            'assistance_requested' => false,
            'settled_at' => $now,
            'updated_at' => $now,
        ]);
        $this->openSubtasks()->whereNotNull('settled_at')->update([
            'status' => 'cancelled',
            'assistance_requested' => false,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // The previous statuses are not stored, and cancelled remains a valid end state.
    }

    private function openSubtasks(): Builder
    {
        $query = DB::table('tasks')->whereNotIn('status', ['completed', 'failed', 'cancelled']);

        if (Schema::hasColumn('tasks', 'parent_id')) {
            return $query->whereIn('parent_id', static function (Builder $groups): void {
                $groups->select('id')->from('tasks')->whereNull('parent_id')->where('status', 'cancelled');
            });
        }

        return $query->whereIn('task_group_id', static function (Builder $groups): void {
            $groups->select('id')->from('task_groups')->where('status', 'cancelled');
        });
    }
};
