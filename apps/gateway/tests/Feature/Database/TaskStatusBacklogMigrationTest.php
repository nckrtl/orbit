<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Runs every migration except the status rename on a separate database, then returns the rename. The default
 * test connection wraps each test in a transaction, where SQLite ignores the foreign-key switch that keeps a
 * table rebuild from cascading. `php artisan migrate` runs SQLite migrations outside a transaction.
 */
function task_status_backlog_migration(): object
{
    config()->set('database.connections.task_status', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('task_status');
    $paths = array_values(array_filter(glob(database_path('migrations/*.php')), static fn (string $path): bool => ! str_contains($path, 'rename_task_statuses_to_backlog_and_todo')));
    Artisan::call('migrate', ['--database' => 'task_status', '--path' => $paths, '--realpath' => true, '--force' => true]);

    return require glob(database_path('migrations/*rename_task_statuses_to_backlog_and_todo.php'))[0];
}

function task_status_column_default(string $table): ?string
{
    return DB::selectOne("select dflt_value from pragma_table_info('{$table}') where name = 'status'")->dflt_value;
}

it('rewrites queued groups and pending subtasks to todo and keeps every row and reference', function (): void {
    $default = DB::getDefaultConnection();
    try {
        $migration = task_status_backlog_migration();
        $appId = DB::table('apps')->insertGetId(['name' => 'status', 'slug' => 'status', 'code' => 'STA', 'repository_url' => 'git@example.test:status.git', 'repository_identity' => 'example.test/status']);
        $queued = DB::table('task_groups')->insertGetId(['app_id' => $appId, 'title' => 'Queued', 'brief' => 'Brief', 'status' => 'queued']);
        $running = DB::table('task_groups')->insertGetId(['app_id' => $appId, 'title' => 'Running', 'brief' => 'Brief', 'status' => 'running']);
        $pending = DB::table('tasks')->insertGetId(['task_group_id' => $queued, 'position' => 1, 'title' => 'Pending', 'brief' => 'Brief', 'status' => 'pending']);
        $completed = DB::table('tasks')->insertGetId(['task_group_id' => $running, 'position' => 1, 'title' => 'Done', 'brief' => 'Brief', 'status' => 'completed']);
        $thread = DB::table('agent_threads')->insertGetId(['driver' => 't3', 'runtime_key' => 'node:1', 'external_id' => 'reviewer', 'task_group_id' => $running, 'role' => 'reviewer']);

        $migration->up();

        expect(DB::table('task_groups')->where('id', $queued)->value('status'))->toBe('todo')
            ->and(DB::table('task_groups')->where('id', $running)->value('status'))->toBe('running')
            ->and(DB::table('tasks')->where('id', $pending)->value('status'))->toBe('todo')
            ->and(DB::table('tasks')->where('id', $completed)->value('status'))->toBe('completed')
            ->and(DB::table('agent_threads')->where('id', $thread)->value('task_group_id'))->toBe($running)
            ->and(task_status_column_default('task_groups'))->toBe("'backlog'")
            ->and(task_status_column_default('tasks'))->toBe("'todo'")
            ->and(DB::select('pragma foreign_key_check'))->toBe([]);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('task_status');
    }
});

it('refuses to roll back', function (): void {
    $migration = require glob(database_path('migrations/*rename_task_statuses_to_backlog_and_todo.php'))[0];

    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});
