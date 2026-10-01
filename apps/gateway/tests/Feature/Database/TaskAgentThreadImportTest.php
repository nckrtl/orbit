<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('imports legacy thread links with configured effort and preserves stored effort', function (string $scenario): void {
    config()->set('orbit.tasks.implementer_effort', 'medium');
    config()->set('orbit.tasks.reviewer_effort', 'low');
    $default = DB::getDefaultConnection();
    config()->set('database.connections.agent_import', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('agent_import');
    try {
        Schema::create('nodes', static function (Blueprint $table): void {
            $table->id();
        });
        DB::table('nodes')->insert(['id' => 3]);
        Schema::create('app_instances', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('node_id');
        });
        Schema::create('task_groups', static function (Blueprint $table): void {
            $table->id();
            $table->string('taskable_type');
            $table->unsignedBigInteger('taskable_id');
            $table->string('reviewer_thread_id')->nullable();
            $table->string('reviewer_model');
            $table->string('implementer_model');
        });
        Schema::create('tasks', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_group_id');
            $table->string('implementer_thread_id')->nullable();
        });
        $create = require database_path('migrations/2026_09_21_124805_create_task_agent_sessions_table.php');
        $create->up();
        $addEffort = require database_path('migrations/2026_09_21_150000_add_model_and_effort_to_task_agent_sessions.php');
        $addEffort->up();
        DB::table('app_instances')->insert(['id' => 1, 'node_id' => 3]);
        DB::table('task_groups')->insert([
            'id' => 1, 'taskable_type' => 'instance', 'taskable_id' => 1,
            'reviewer_thread_id' => 'legacy-review', 'reviewer_model' => 'claude-opus-5', 'implementer_model' => 'gpt-5.6-luna',
        ]);
        DB::table('tasks')->insert(['id' => 1, 'task_group_id' => 1, 'implementer_thread_id' => 'legacy-implement']);
        if ($scenario !== 'pointers') {
            DB::table('task_agent_sessions')->insert([
                'id' => 42, 'task_group_id' => 1, 'node_id' => 3, 'task_id' => null,
                'role' => $scenario === 'conflict' ? 'implementer' : 'reviewer', 'thread_id' => 'legacy-review',
                'model' => 'claude-opus-5', 'effort' => 'high',
            ]);
        }
        $migration = require database_path('migrations/2026_09_21_213825_create_agent_threads_from_task_agent_sessions.php');
        if ($scenario === 'conflict') {
            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'ownership is ambiguous');
            expect(Schema::hasTable('task_agent_sessions'))->toBeTrue()
                ->and(Schema::hasTable('agent_threads'))->toBeFalse();
            DB::table('task_agent_sessions')->where('id', 42)->update(['role' => 'reviewer']);
        }

        $migration->up();

        $reviewer = DB::table('agent_threads')->where('external_id', 'legacy-review')->first();
        $implementer = DB::table('agent_threads')->where('external_id', 'legacy-implement')->first();
        expect(DB::table('agent_threads')->count())->toBe(2)
            ->and($reviewer?->id)->toBe($scenario === 'pointers' ? 1 : 42)
            ->and($reviewer?->node_id)->toBe(3)
            ->and($reviewer?->driver)->toBe('t3')
            ->and($reviewer?->runtime_key)->toBe('node:3')
            ->and($reviewer?->effort)->toBe($scenario === 'pointers' ? 'low' : 'high')
            ->and($implementer?->effort)->toBe('medium')
            ->and($implementer?->task_id)->toBe(1)
            ->and(DB::table('task_groups')->where('id', 1)->value('reviewer_agent_thread_id'))->toBe($reviewer?->id)
            ->and(DB::table('tasks')->where('id', 1)->value('implementer_agent_thread_id'))->toBe($implementer?->id);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('agent_import');
    }
})->with(['persisted', 'pointers', 'conflict']);
