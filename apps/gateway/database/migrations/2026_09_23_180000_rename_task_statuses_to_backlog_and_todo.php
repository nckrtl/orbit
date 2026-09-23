<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR 0122: groups start in backlog, and the ready state of groups and subtasks is todo. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('task_groups')->where('status', 'queued')->update(['status' => 'todo']);
        DB::table('tasks')->where('status', 'pending')->update(['status' => 'todo']);
        Schema::table('task_groups', static function (Blueprint $table): void {
            $table->string('status')->default('backlog')->change();
        });
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->string('status')->default('todo')->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Task statuses cannot be safely rolled back once groups are held in backlog. Restore a database backup or apply a forward migration.');
    }
};
