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
        Schema::rename('task_agent_sessions', 'agent_threads');
        Schema::table('agent_threads', static function (Blueprint $table): void {
            $table->dropUnique('task_agent_sessions_thread_id_unique');
            $table->renameColumn('thread_id', 'external_id');
            $table->string('driver')->default('t3');
            $table->string('runtime_key')->nullable();
            $table->string('state')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->text('observation_error')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('tokens')->nullable();
            $table->unsignedBigInteger('lines_added')->nullable();
            $table->unsignedBigInteger('lines_deleted')->nullable();
        });
        foreach (DB::table('agent_threads')->orderBy('id')->cursor() as $thread) {
            DB::table('agent_threads')->where('id', $thread->id)->update([
                'runtime_key' => $thread->node_id === null ? 'legacy:'.$thread->id : 'node:'.$thread->node_id,
            ]);
        }
        Schema::table('agent_threads', static function (Blueprint $table): void {
            $table->string('runtime_key')->nullable(false)->change();
            $table->unique(['driver', 'runtime_key', 'external_id']);
        });
        Schema::table('task_groups', static function (Blueprint $table): void {
            $table->string('agent_driver')->default('t3');
            $table->foreignId('reviewer_agent_thread_id')->nullable()->constrained('agent_threads')->nullOnDelete();
        });
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->foreignId('implementer_agent_thread_id')->nullable()->constrained('agent_threads')->nullOnDelete();
        });
        foreach (DB::table('task_groups')->orderBy('id')->cursor() as $group) {
            $nodeId = in_array($group->taskable_type, ['instance', 'App\\Models\\AppInstance'], true)
                ? DB::table('app_instances')->where('id', $group->taskable_id)->value('node_id') : null;
            $this->link('task_groups', $group->id, $group->id, null, $nodeId, 'reviewer', $group->reviewer_thread_id, $group->reviewer_model);
            foreach (DB::table('tasks')->where('task_group_id', $group->id)->orderBy('id')->cursor() as $task) {
                $this->link('tasks', $task->id, $group->id, $task->id, $nodeId, 'implementer', $task->implementer_thread_id, $group->implementer_model);
            }
        }
        Schema::table('task_groups', static fn (Blueprint $table) => $table->dropColumn('reviewer_thread_id'));
        Schema::table('tasks', static fn (Blueprint $table) => $table->dropColumn('implementer_thread_id'));
    }

    private function link(string $table, int $id, int $groupId, ?int $taskId, ?int $nodeId, string $role, ?string $externalId, string $model): void
    {
        if ($externalId === null || $externalId === '') {
            return;
        }
        $thread = DB::table('agent_threads')->where('external_id', $externalId)->first();
        if ($thread !== null && ($thread->task_group_id !== $groupId || $thread->task_id !== $taskId || $thread->role !== $role)) {
            throw new RuntimeException('Agent conversation ownership is ambiguous.');
        }
        $threadId = $thread->id ?? DB::table('agent_threads')->insertGetId([
            'task_group_id' => $groupId, 'task_id' => $taskId, 'node_id' => $nodeId,
            'driver' => 't3', 'runtime_key' => $nodeId === null ? 'legacy:'.$table.':'.$id : 'node:'.$nodeId,
            'external_id' => $externalId, 'role' => $role, 'model' => $model,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table($table)->where('id', $id)->update([$role.'_agent_thread_id' => $threadId]);
    }

    public function down(): void
    {
        throw new RuntimeException('Agent thread identities cannot be safely rolled back after other drivers are used. Restore a database backup or apply a forward migration.');
    }
};
