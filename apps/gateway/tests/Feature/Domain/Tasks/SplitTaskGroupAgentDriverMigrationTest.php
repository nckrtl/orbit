<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Runs every migration except the split on a separate database, then returns the split migration. */
function split_driver_migration(): object
{
    config()->set('database.connections.split_driver', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('split_driver');
    $paths = array_values(array_filter(glob(database_path('migrations/*.php')), static fn (string $path): bool => ! str_contains($path, 'split_task_group_agent_driver_by_role')));
    Artisan::call('migrate', ['--database' => 'split_driver', '--path' => $paths, '--realpath' => true, '--force' => true]);

    return require glob(database_path('migrations/*split_task_group_agent_driver_by_role.php'))[0];
}

function split_driver_group(string $driver, string $code): int
{
    $appId = DB::table('apps')->insertGetId(['name' => 'split-'.$driver, 'slug' => 'split-'.$driver, 'code' => $code, 'repository_url' => 'git@example.test:split.git', 'repository_identity' => 'example.test/split-'.$driver]);

    return DB::table('task_groups')->insertGetId(['app_id' => $appId, 'title' => 'Split', 'brief' => 'Split', 'agent_driver' => $driver]);
}

it('copies the existing group driver into both roles and removes the single column', function (): void {
    $default = DB::getDefaultConnection();
    try {
        $migration = split_driver_migration();
        $t3 = split_driver_group('t3', 'TTT');
        $pi = split_driver_group('pi', 'PPP');

        $migration->up();

        expect(Schema::hasColumn('task_groups', 'agent_driver'))->toBeFalse()
            ->and((array) DB::table('task_groups')->where('id', $t3)->first(['implementer_agent_driver', 'reviewer_agent_driver']))
            ->toBe(['implementer_agent_driver' => 't3', 'reviewer_agent_driver' => 't3'])
            ->and((array) DB::table('task_groups')->where('id', $pi)->first(['implementer_agent_driver', 'reviewer_agent_driver']))
            ->toBe(['implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('split_driver');
    }
});

it('rolls back only while every group uses one driver for both roles', function (): void {
    $default = DB::getDefaultConnection();
    try {
        $migration = split_driver_migration();
        $group = split_driver_group('t3', 'TTT');
        $migration->up();
        DB::table('task_groups')->where('id', $group)->update(['implementer_agent_driver' => 'pi']);

        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'different implementer and reviewer drivers');
        expect(Schema::hasColumn('task_groups', 'agent_driver'))->toBeFalse();

        DB::table('task_groups')->where('id', $group)->update(['implementer_agent_driver' => 't3']);
        $migration->down();

        expect(DB::table('task_groups')->where('id', $group)->value('agent_driver'))->toBe('t3')
            ->and(Schema::hasColumn('task_groups', 'implementer_agent_driver'))->toBeFalse();
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('split_driver');
    }
});
