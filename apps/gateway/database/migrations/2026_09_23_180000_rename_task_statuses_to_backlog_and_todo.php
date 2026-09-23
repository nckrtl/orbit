<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** ADR 0122: groups start in backlog, and the ready state of groups and subtasks is todo. */
return new class extends Migration
{
    public function up(): void
    {
        // The models set the new defaults. Changing the column defaults would rebuild task_groups on SQLite, and dropping
        // the old table cascades into tasks and deletes every subtask.
        DB::table('task_groups')->where('status', 'queued')->update(['status' => 'todo']);
        DB::table('tasks')->where('status', 'pending')->update(['status' => 'todo']);
    }

    public function down(): void
    {
        throw new RuntimeException('Task statuses cannot be safely rolled back once groups are held in backlog. Restore a database backup or apply a forward migration.');
    }
};
