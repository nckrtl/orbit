<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('moves legacy annotation state into tasks without changing annotation identity or delivery commands', function (): void {
    $original = DB::getDefaultConnection();
    config(['database.connections.annotation_migration' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection('annotation_migration');
    try {
        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('app_instances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('app_id');
        });
        (require database_path('migrations/2026_09_20_180000_create_task_groups_and_tasks_tables.php'))->up();
        Schema::table('task_groups', function (Blueprint $table): void {
            $table->string('agent_driver')->default('t3');
        });
        (require database_path('migrations/2026_09_22_143449_create_annotations_and_annotation_events_tables.php'))->up();
        DB::table('apps')->insert(['id' => 1]);
        DB::table('app_instances')->insert(['id' => 3, 'app_id' => 1]);
        foreach (['pending', 'in_progress', 'resolved'] as $index => $status) {
            DB::table('annotations')->insert([
                'id' => 'legacy-'.$index, 'app_instance_id' => 3, 'thread_id' => 'selected-thread',
                'context' => json_encode(['comment' => 'Legacy instruction']), 'status' => $status,
                'summary' => $status === 'resolved' ? 'Verified result' : null,
                'command_id' => 'command-'.$index, 'message_id' => 'message-'.$index,
                'command' => '{"commandId":"keep-this-command"}', 'revision' => $index + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        (require database_path('migrations/2026_09_22_160124_back_annotations_with_tasks.php'))->up();
        expect(DB::table('tasks')->orderBy('id')->pluck('status')->all())->toBe(['pending', 'running', 'completed']);
        expect(DB::table('task_groups')->pluck('execution_mode')->unique()->all())->toBe(['existing_thread']);
        expect(DB::table('tasks')->where('status', 'completed')->value('completion_summary'))->toBe('Verified result');
        expect(DB::table('tasks')->pluck('target_thread_id')->unique()->all())->toBe(['selected-thread']);
        expect(DB::table('annotations')->where('id', 'legacy-2')->value('command'))->toBe('{"commandId":"keep-this-command"}');
        expect(DB::table('annotations')->whereNull('task_id')->count())->toBe(0);
        expect(Schema::hasColumn('annotations', 'status'))->toBeFalse();
        expect(Schema::hasColumn('annotations', 'summary'))->toBeFalse();
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('annotation_migration');
    }
});
