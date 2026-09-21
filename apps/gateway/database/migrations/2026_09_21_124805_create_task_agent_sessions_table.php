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
        Schema::create('task_agent_sessions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role');
            $table->string('thread_id')->unique();
            $table->timestamps();
        });

        foreach (DB::table('task_groups')->orderBy('id')->get() as $group) {
            $nodeId = in_array($group->taskable_type, ['instance', 'App\\Models\\AppInstance'], true)
                ? DB::table('app_instances')->where('id', $group->taskable_id)->value('node_id')
                : null;
            $links = [['task_id' => null, 'role' => 'reviewer', 'thread_id' => $group->reviewer_thread_id]];
            foreach (DB::table('tasks')->where('task_group_id', $group->id)->get() as $task) {
                $links[] = ['task_id' => $task->id, 'role' => 'implementer', 'thread_id' => $task->implementer_thread_id];
            }
            foreach ($links as $link) {
                if (! is_string($link['thread_id']) || $link['thread_id'] === '') {
                    continue;
                }
                DB::table('task_agent_sessions')->insert([
                    ...$link, 'task_group_id' => $group->id, 'node_id' => $nodeId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_agent_sessions');
    }
};
