<?php

declare(strict_types=1);

use App\Models\App as OrbitApp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

function task_status_backlog_migration(): Migration
{
    return require database_path('migrations/2026_09_23_180000_rename_task_statuses_to_backlog_and_todo.php');
}

it('rewrites queued groups and pending subtasks to todo and keeps other rows', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Status migration',
        'slug' => 'status-migration',
        'repository_url' => 'git@example.test:status-migration.git',
    ]);
    $queued = DB::table('task_groups')->insertGetId(['app_id' => $app->id, 'title' => 'Queued', 'brief' => 'Brief', 'status' => 'queued']);
    $running = DB::table('task_groups')->insertGetId(['app_id' => $app->id, 'title' => 'Running', 'brief' => 'Brief', 'status' => 'running']);
    $pending = DB::table('tasks')->insertGetId(['task_group_id' => $running, 'position' => 2, 'title' => 'Pending', 'brief' => 'Brief', 'status' => 'pending']);
    $completed = DB::table('tasks')->insertGetId(['task_group_id' => $running, 'position' => 1, 'title' => 'Done', 'brief' => 'Brief', 'status' => 'completed']);

    task_status_backlog_migration()->up();

    expect(DB::table('task_groups')->where('id', $queued)->value('status'))->toBe('todo')
        ->and(DB::table('task_groups')->where('id', $running)->value('status'))->toBe('running')
        ->and(DB::table('tasks')->where('id', $pending)->value('status'))->toBe('todo')
        ->and(DB::table('tasks')->where('id', $completed)->value('status'))->toBe('completed');
});

it('refuses to roll back', function (): void {
    expect(fn () => task_status_backlog_migration()->down())->toThrow(RuntimeException::class);
});
