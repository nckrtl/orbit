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
        if (DB::table('annotations')->leftJoin('app_instances', 'annotations.app_instance_id', '=', 'app_instances.id')->whereNull('app_instances.id')->exists()) {
            throw new RuntimeException('Cannot migrate annotations without an owning Instance. Restore their ownership before retrying.');
        }
        Schema::table('task_groups', function (Blueprint $table): void {
            $table->string('execution_mode')->default('managed')->index();
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('type')->default('implementation');
            $table->string('target_thread_id', 128)->nullable()->index();
            $table->text('completion_summary')->nullable();
        });
        Schema::table('annotations', function (Blueprint $table): void {
            $table->foreignId('task_id')->nullable()->unique()->constrained('tasks')->restrictOnDelete();
        });
        foreach (DB::table('annotations')->orderBy('id')->get() as $annotation) {
            $context = json_decode($annotation->context, true, flags: JSON_THROW_ON_ERROR);
            $status = match ($annotation->status) {
                'resolved' => 'completed', 'in_progress' => 'running', default => 'pending',
            };
            $title = mb_substr((string) ($context['comment'] ?? 'Annotation'), 0, 200);
            $groupId = DB::table('task_groups')->insertGetId([
                'app_id' => DB::table('app_instances')->where('id', $annotation->app_instance_id)->value('app_id'),
                'taskable_type' => 'App\\Models\\AppInstance', 'taskable_id' => $annotation->app_instance_id,
                'title' => $title, 'brief' => (string) ($context['comment'] ?? ''),
                'execution_mode' => 'existing_thread', 'agent_driver' => 't3',
                'status' => $status === 'pending' ? 'queued' : $status,
                'created_at' => $annotation->created_at, 'updated_at' => $annotation->updated_at,
                'settled_at' => $status === 'completed' ? $annotation->updated_at : null,
            ]);
            $taskId = DB::table('tasks')->insertGetId([
                'task_group_id' => $groupId, 'position' => 1, 'type' => 'annotation', 'title' => $title,
                'brief' => (string) ($context['comment'] ?? ''), 'status' => $status,
                'target_thread_id' => $annotation->thread_id, 'completion_summary' => $annotation->summary,
                'created_at' => $annotation->created_at, 'updated_at' => $annotation->updated_at,
                'settled_at' => $status === 'completed' ? $annotation->updated_at : null,
            ]);
            DB::table('annotations')->where('id', $annotation->id)->update(['task_id' => $taskId]);
        }
        Schema::table('annotations', function (Blueprint $table): void {
            $table->dropIndex(['app_instance_id', 'status', 'created_at']);
            $table->dropColumn(['status', 'summary', 'thread_id']);
            $table->index(['app_instance_id', 'created_at']);
            $table->unsignedBigInteger('task_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Task-backed annotations require a forward fix or a pre-migration database backup.');
    }
};
