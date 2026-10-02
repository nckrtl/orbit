<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('moves groups, subtasks, and every reference into one tasks table without loss', function (): void {
    $default = DB::getDefaultConnection();
    config(['database.connections.one_task_model' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('one_task_model');

    try {
        $paths = array_values(array_filter(
            glob(database_path('migrations/*.php')) ?: [],
            static fn (string $path): bool => basename($path) < '2026_10_05_000000_merge_task_groups_into_tasks.php',
        ));
        Artisan::call('migrate', ['--database' => 'one_task_model', '--path' => $paths, '--realpath' => true, '--force' => true]);

        $projectId = DB::table('projects')->insertGetId([
            'name' => 'Orbit', 'slug' => 'orbit', 'code' => 'ORB',
            'repository_url' => 'git@example.test:orbit.git', 'repository_identity' => 'example.test/orbit',
        ]);
        $nodeId = DB::table('nodes')->insertGetId([
            'name' => 'gateway', 'public_ssh_host' => '10.44.0.7', 'status' => 'active', 'platform' => 'linux',
        ]);
        $instanceId = DB::table('instances')->insertGetId([
            'project_id' => $projectId, 'node_id' => $nodeId, 'name' => 'task-1', 'checkout_path' => '/srv/task-1', 'status' => 'reserved',
        ]);
        DB::table('task_groups')->insert([
            'id' => 1, 'project_id' => $projectId, 'taskable_type' => 'instance', 'taskable_id' => $instanceId,
            'title' => 'Group One', 'brief' => 'Keep this id.', 'status' => 'running', 'pr_url' => 'https://example.test/pull/1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('task_groups')->insert([
            'id' => 2, 'project_id' => $projectId, 'title' => 'Annotation group', 'brief' => 'An existing thread.',
            'status' => 'running', 'execution_mode' => 'existing_thread', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tasks')->insert([
            'id' => 1, 'task_group_id' => 1, 'position' => 1, 'title' => 'Sub One', 'brief' => 'First subtask.',
            'status' => 'completed', 'type' => 'implementation', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tasks')->insert([
            'id' => 2, 'task_group_id' => 1, 'position' => 2, 'title' => 'Fix the pull request', 'brief' => 'Repair the failure.',
            'status' => 'todo', 'type' => 'implementation', 'continuation_of_task_id' => 1, 'fixup_problem' => 'Checks failed.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tasks')->insert([
            'id' => 3, 'task_group_id' => 2, 'position' => 1, 'title' => 'Annotation subtask', 'brief' => 'Send the note.',
            'status' => 'running', 'type' => 'annotation', 'target_thread_id' => 'thread-9',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $reviewerId = DB::table('agent_threads')->insertGetId([
            'task_group_id' => 1, 'task_id' => null, 'role' => 'reviewer', 'driver' => 't3', 'runtime_key' => 'node:1',
            'external_id' => 'reviewer-1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $implementerId = DB::table('agent_threads')->insertGetId([
            'task_group_id' => 1, 'task_id' => 1, 'role' => 'implementer', 'driver' => 't3', 'runtime_key' => 'node:1',
            'external_id' => 'implementer-1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $commentId = DB::table('task_comments')->insertGetId([
            'task_group_id' => 1, 'task_id' => 1, 'type' => 'approved', 'body' => 'Approved.', 'author' => 'reviewer',
            'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $checkId = DB::table('task_checks')->insertGetId([
            'task_id' => 2, 'status' => 'failed', 'pid' => 42, 'process_started' => '123', 'head_before' => str_repeat('a', 64),
            'tree_before' => str_repeat('b', 64), 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('annotations')->insert([
            'id' => 'note-1', 'instance_id' => $instanceId, 'task_id' => 3, 'context' => '{"comment":"Note"}',
            'command_id' => 'command-1', 'message_id' => 'message-1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $decisionId = DB::table('jev_decisions')->insertGetId([
            'purpose' => 'brief_coverage', 'call_started_at' => now(), 'task_group_id' => 1, 'task_id' => 2,
            'task_ids' => json_encode([1, 2], JSON_THROW_ON_ERROR), 'questions' => '[]', 'input_state' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([
            ['subtask one', 'App\\Models\\Task', 1],
            ['fixup', 'App\\Models\\Task', 2],
            ['annotation', 'App\\Models\\Task', 3],
            ['group one', 'App\\Models\\TaskGroup', 1],
            ['group two', 'App\\Models\\TaskGroup', 2],
            ['project', 'App\\Models\\Project', 1],
        ] as [$description, $subjectType, $subjectId]) {
            one_task_activity($description, $subjectType, $subjectId);
        }

        $migration = require database_path('migrations/2026_10_05_000000_merge_task_groups_into_tasks.php');
        $migration->up();

        $subOne = (int) DB::table('tasks')->where('title', 'Sub One')->value('id');
        $fixup = (int) DB::table('tasks')->where('title', 'Fix the pull request')->value('id');
        $annotation = (int) DB::table('tasks')->where('title', 'Annotation subtask')->value('id');
        $groupMax = 2;

        expect(Schema::hasTable('task_groups'))->toBeFalse()
            ->and(DB::select("SELECT name FROM sqlite_master WHERE type = 'view'"))->toBe([])
            ->and(DB::table('tasks')->count())->toBe(5)
            ->and(DB::table('tasks')->whereNull('parent_id')->orderBy('id')->pluck('title')->all())->toBe(['Group One', 'Annotation group'])
            ->and(DB::table('tasks')->where('id', 1)->value('title'))->toBe('Group One')
            ->and(DB::table('tasks')->where('id', 1)->value('pr_url'))->toBe('https://example.test/pull/1')
            ->and(DB::table('tasks')->where('id', 1)->value('taskable_id'))->toBe($instanceId)
            ->and(DB::table('tasks')->where('id', 2)->value('execution_mode'))->toBe('existing_thread')
            ->and($subOne)->toBeGreaterThan($groupMax)
            ->and($fixup)->toBeGreaterThan($groupMax)
            ->and($annotation)->toBeGreaterThan($groupMax)
            ->and(DB::table('tasks')->where('id', $subOne)->value('parent_id'))->toBe(1)
            ->and(DB::table('tasks')->where('id', $fixup)->value('parent_id'))->toBe(1)
            ->and(DB::table('tasks')->where('id', $fixup)->value('continuation_of_task_id'))->toBe($subOne)
            ->and(DB::table('tasks')->where('id', $fixup)->value('fixup_problem'))->toBe('Checks failed.')
            ->and(DB::table('tasks')->where('id', $annotation)->value('parent_id'))->toBe(2)
            ->and(DB::table('tasks')->where('id', $annotation)->value('target_thread_id'))->toBe('thread-9')
            ->and(DB::table('agent_threads')->where('id', $reviewerId)->value('task_group_id'))->toBe(1)
            ->and(DB::table('agent_threads')->where('id', $reviewerId)->value('task_id'))->toBeNull()
            ->and(DB::table('agent_threads')->where('id', $implementerId)->value('task_id'))->toBe($subOne)
            ->and(DB::table('agent_threads')->where('id', $implementerId)->value('task_group_id'))->toBe(1)
            ->and(DB::table('task_comments')->where('id', $commentId)->value('task_id'))->toBe($subOne)
            ->and(DB::table('task_comments')->where('id', $commentId)->value('task_group_id'))->toBe(1)
            ->and(DB::table('task_comments')->where('id', $commentId)->value('body'))->toBe('Approved.')
            ->and(DB::table('task_checks')->where('id', $checkId)->value('task_id'))->toBe($fixup)
            ->and(DB::table('annotations')->where('id', 'note-1')->value('task_id'))->toBe($annotation)
            ->and(DB::table('jev_decisions')->where('id', $decisionId)->value('task_group_id'))->toBe(1)
            ->and(DB::table('jev_decisions')->where('id', $decisionId)->value('task_id'))->toBe($fixup)
            ->and(json_decode((string) DB::table('jev_decisions')->where('id', $decisionId)->value('task_ids'), true))->toBe([$subOne, $fixup])
            ->and(one_task_activity_subject('subtask one'))->toBe(['App\\Models\\Task', $subOne])
            ->and(one_task_activity_subject('fixup'))->toBe(['App\\Models\\Task', $fixup])
            ->and(one_task_activity_subject('annotation'))->toBe(['App\\Models\\Task', $annotation])
            ->and(one_task_activity_subject('group one'))->toBe(['App\\Models\\Task', 1])
            ->and(one_task_activity_subject('group two'))->toBe(['App\\Models\\Task', 2])
            ->and(one_task_activity_subject('project'))->toBe(['App\\Models\\Project', 1])
            ->and(DB::select('pragma foreign_key_check'))->toBe([])
            ->and(array_column(Schema::getIndexes('tasks'), 'name'))->toContain(
                'tasks_parent_id_position_unique',
                'tasks_project_id_status_index',
                'tasks_status_index',
                'tasks_execution_mode_index',
                'tasks_target_thread_id_index',
                'tasks_taskable_type_taskable_id_index',
            )
            ->and(array_values(array_filter(
                array_column(Schema::getIndexes('tasks'), 'name'),
                static fn (string $name): bool => str_contains($name, 'tasks_merged'),
            )))->toBe([]);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('one_task_model');
    }
});

it('rolls the merge back when a reference cannot move', function (): void {
    $default = DB::getDefaultConnection();
    config(['database.connections.one_task_model_rollback' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('one_task_model_rollback');

    try {
        $paths = array_values(array_filter(
            glob(database_path('migrations/*.php')) ?: [],
            static fn (string $path): bool => basename($path) < '2026_10_05_000000_merge_task_groups_into_tasks.php',
        ));
        Artisan::call('migrate', ['--database' => 'one_task_model_rollback', '--path' => $paths, '--realpath' => true, '--force' => true]);

        $projectId = DB::table('projects')->insertGetId([
            'name' => 'Orbit', 'slug' => 'orbit', 'code' => 'ORB',
            'repository_url' => 'git@example.test:orbit.git', 'repository_identity' => 'example.test/orbit',
        ]);
        DB::table('task_groups')->insert([
            'id' => 1, 'project_id' => $projectId, 'title' => 'Group One', 'brief' => 'Keep this id.',
            'status' => 'running', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tasks')->insert([
            'id' => 1, 'task_group_id' => 1, 'position' => 1, 'title' => 'Sub One', 'brief' => 'First subtask.',
            'status' => 'todo', 'type' => 'implementation', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $threadId = DB::table('agent_threads')->insertGetId([
            'task_group_id' => 1, 'task_id' => 1, 'role' => 'implementer', 'driver' => 't3', 'runtime_key' => 'node:1',
            'external_id' => 'implementer-1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $commentId = DB::table('task_comments')->insertGetId([
            'task_group_id' => 1, 'task_id' => 1, 'type' => 'approved', 'body' => 'Approved.', 'author' => 'reviewer',
            'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $checkId = DB::table('task_checks')->insertGetId([
            'task_id' => 1, 'status' => 'failed', 'pid' => 42, 'process_started' => '123', 'head_before' => str_repeat('a', 64),
            'tree_before' => str_repeat('b', 64), 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $decisionId = DB::table('jev_decisions')->insertGetId([
            'purpose' => 'brief_coverage', 'call_started_at' => now(), 'task_group_id' => 1, 'task_id' => 1,
            'task_ids' => json_encode([1, 99], JSON_THROW_ON_ERROR), 'questions' => '[]', 'input_state' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        one_task_activity('rolled back subtask', 'App\\Models\\Task', 1);
        one_task_activity('rolled back group', 'App\\Models\\TaskGroup', 1);
        $migration = require database_path('migrations/2026_10_05_000000_merge_task_groups_into_tasks.php');

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'lists task 99, which is not a subtask');

        expect(Schema::hasTable('task_groups'))->toBeTrue()
            ->and(Schema::hasColumn('tasks', 'task_group_id'))->toBeTrue()
            ->and(Schema::hasColumn('tasks', 'parent_id'))->toBeFalse()
            ->and(DB::table('task_groups')->where('id', 1)->value('title'))->toBe('Group One')
            ->and(DB::table('tasks')->where('id', 1)->value('title'))->toBe('Sub One')
            ->and(DB::table('tasks')->where('id', 1)->value('task_group_id'))->toBe(1)
            ->and(DB::table('agent_threads')->where('id', $threadId)->value('task_id'))->toBe(1)
            ->and(DB::table('task_comments')->where('id', $commentId)->value('task_id'))->toBe(1)
            ->and(DB::table('task_checks')->where('id', $checkId)->value('task_id'))->toBe(1)
            ->and(json_decode((string) DB::table('jev_decisions')->where('id', $decisionId)->value('task_ids'), true))->toBe([1, 99])
            ->and(one_task_activity_subject('rolled back subtask'))->toBe(['App\\Models\\Task', 1])
            ->and(one_task_activity_subject('rolled back group'))->toBe(['App\\Models\\TaskGroup', 1]);

        DB::table('jev_decisions')->where('id', $decisionId)->update([
            'task_ids' => json_encode([1], JSON_THROW_ON_ERROR),
        ]);
        $migration->up();

        $subOne = (int) DB::table('tasks')->where('title', 'Sub One')->value('id');

        expect(Schema::hasTable('task_groups'))->toBeFalse()
            ->and(DB::table('tasks')->where('title', 'Group One')->value('id'))->toBe(1)
            ->and(DB::table('tasks')->where('title', 'Sub One')->value('parent_id'))->toBe(1)
            ->and(DB::table('agent_threads')->where('id', $threadId)->value('task_group_id'))->toBe(1)
            ->and(one_task_activity_subject('rolled back subtask'))->toBe(['App\\Models\\Task', $subOne])
            ->and(one_task_activity_subject('rolled back group'))->toBe(['App\\Models\\Task', 1]);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('one_task_model_rollback');
    }
});

function one_task_activity(string $description, string $subjectType, int $subjectId): void
{
    DB::table('activity_log')->insert([
        'description' => $description,
        'subject_type' => $subjectType,
        'subject_id' => $subjectId,
        'request_id' => '00000000-0000-4000-8000-000000000001',
        'command' => 'tasks:show',
        'status' => 'success',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array{0: string, 1: int} */
function one_task_activity_subject(string $description): array
{
    $row = DB::table('activity_log')->where('description', $description)->first(['subject_type', 'subject_id']);

    return [(string) $row->subject_type, (int) $row->subject_id];
}
