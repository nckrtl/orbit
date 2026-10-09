<?php

declare(strict_types=1);

use App\Http\Requests\Tasks\UpdateTaskRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

it('fails loudly when a legacy test deliverable does not name one exact test file', function (): void {
    $default = DB::getDefaultConnection();
    try {
        config()->set('database.connections.test_deliverables_migration', [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection('test_deliverables_migration');
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')) ?: [], static fn (string $path): bool => ! str_contains($path, 'merge_task_groups_into_tasks') && ! str_contains($path, 'allow_owned_project_sandbox_instance_removal')));
        Artisan::call('migrate', ['--database' => 'test_deliverables_migration', '--path' => $paths, '--realpath' => true, '--force' => true]);

        $projectId = DB::table('projects')->insertGetId([
            'name' => 'Migration fixture', 'slug' => 'migration-fixture', 'code' => 'MIG',
            'repository_url' => 'git@example.test:migration.git', 'repository_identity' => 'example.test/migration',
            'task_check' => 'cd apps/gateway && composer test',
        ]);
        $groupId = DB::table('task_groups')->insertGetId(['project_id' => $projectId, 'title' => 'Open', 'brief' => 'Brief', 'status' => 'todo']);
        DB::table('tasks')->insert([
            'task_group_id' => $groupId, 'position' => 1, 'title' => 'Glob test', 'brief' => 'Brief', 'status' => 'todo',
            'deliverables' => json_encode([[
                'id' => 'glob-test', 'type' => 'test', 'description' => 'Do not convert a glob.',
                'project' => '.', 'file' => 'tests/Feature/**/*.php', 'name' => 'the test',
            ]], JSON_THROW_ON_ERROR),
        ]);

        $migration = require database_path('migrations/2026_09_29_130000_convert_test_deliverables_to_commands.php');
        expect(fn () => run_legacy_schema_migration($migration, 'up'))->toThrow(RuntimeException::class, 'unsafe project, file, or test name');
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('test_deliverables_migration');
    }
});

it('converts stored test deliverables with the explicit legacy Pest mapping', function (): void {
    $default = DB::getDefaultConnection();
    try {
        config()->set('database.connections.test_deliverables_migration', [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection('test_deliverables_migration');
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')) ?: [], static fn (string $path): bool => ! str_contains($path, 'merge_task_groups_into_tasks') && ! str_contains($path, 'allow_owned_project_sandbox_instance_removal')));
        Artisan::call('migrate', ['--database' => 'test_deliverables_migration', '--path' => $paths, '--realpath' => true, '--force' => true]);

        $projectId = DB::table('projects')->insertGetId([
            'name' => 'Migration fixture', 'slug' => 'migration-fixture', 'code' => 'MIG',
            'repository_url' => 'git@example.test:migration.git', 'repository_identity' => 'example.test/migration',
            'task_check' => 'cd apps/gateway && composer test',
        ]);
        $open = DB::table('task_groups')->insertGetId(['project_id' => $projectId, 'title' => 'Open', 'brief' => 'Brief', 'status' => 'todo']);
        $closed = DB::table('task_groups')->insertGetId(['project_id' => $projectId, 'title' => 'Closed', 'brief' => 'Brief', 'status' => 'completed']);
        $legacy = ['id' => 'repro', 'type' => 'test', 'description' => 'Reproduce it', 'project' => './apps//gateway/', 'file' => './tests//Feature/LayoutTest.php', 'name' => 'layout regression', 'fails_on_base' => true];
        $openTask = DB::table('tasks')->insertGetId([
            'task_group_id' => $open, 'position' => 1, 'title' => 'Repro', 'brief' => 'Brief', 'status' => 'todo',
            'deliverables' => json_encode([$legacy], JSON_THROW_ON_ERROR),
        ]);
        $closedTask = DB::table('tasks')->insertGetId([
            'task_group_id' => $closed, 'position' => 1, 'title' => 'Closed', 'brief' => 'Brief', 'status' => 'completed',
            'deliverables' => json_encode([$legacy], JSON_THROW_ON_ERROR),
        ]);
        $longId = str_repeat('a', 64);
        $expandedDeliverables = [[
            'id' => 'repro-file', 'type' => 'review', 'description' => 'Keep this review proof.',
        ]];
        foreach (['repro', $longId, 'test-three', 'test-four', 'test-five'] as $index => $id) {
            $expandedDeliverables[] = [
                'id' => $id,
                'type' => 'test',
                'description' => 'Converted test '.($index + 1),
                'project' => '.',
                'file' => 'tests/Test'.($index + 1).'.php',
                'name' => 'test '.($index + 1),
            ];
        }
        $sourceStart = str_repeat('a', 40);
        $expandedTask = DB::table('tasks')->insertGetId([
            'task_group_id' => $open, 'position' => 2, 'title' => 'Many legacy tests', 'brief' => 'Preserve all test proofs.', 'status' => 'running',
            'subtask_start_commit' => $sourceStart,
            'deliverables' => json_encode($expandedDeliverables, JSON_THROW_ON_ERROR),
        ]);
        $laterTask = DB::table('tasks')->insertGetId([
            'task_group_id' => $open, 'position' => 3, 'title' => 'Later work', 'brief' => 'Must stay after proof continuations.', 'status' => 'todo',
            'deliverables' => json_encode([], JSON_THROW_ON_ERROR),
        ]);
        $migration = require database_path('migrations/2026_09_29_130000_convert_test_deliverables_to_commands.php');
        DB::statement("CREATE TRIGGER fail_continuation_insert BEFORE INSERT ON tasks WHEN NEW.title LIKE 'Many legacy tests (continued %' BEGIN SELECT RAISE(ABORT, 'Injected continuation insert failure'); END");
        expect(fn () => run_legacy_schema_migration($migration, 'up'))->toThrow(QueryException::class, 'Injected continuation insert failure');
        expect(json_decode(DB::table('tasks')->where('id', $expandedTask)->value('deliverables'), true))->toBe($expandedDeliverables)
            ->and(DB::table('tasks')->where('task_group_id', $open)->where('title', 'like', 'Many legacy tests (continued %')->count())->toBe(0)
            ->and(DB::table('tasks')->where('id', $laterTask)->value('position'))->toBe(3);
        DB::statement('DROP TRIGGER fail_continuation_insert');
        run_legacy_schema_migration($migration, 'up');

        expect(json_decode(DB::table('tasks')->where('id', $openTask)->value('deliverables'), true))->toBe([[
            'id' => 'repro',
            'type' => 'command',
            'description' => 'Reproduce it',
            'command' => "cd 'apps/gateway' && vendor/bin/pest 'tests/Feature/LayoutTest.php' --filter='/layout regression/' --colors=never",
            'directory' => '.',
            'fails_on_base' => true,
            'paths' => ['apps/gateway/tests/Feature/LayoutTest.php'],
        ], [
            'id' => 'repro-file',
            'type' => 'file',
            'description' => 'The converted test file is changed.',
            'path' => 'apps/gateway/tests/Feature/LayoutTest.php',
            'change' => 'any',
        ]])->and(json_decode(DB::table('tasks')->where('id', $closedTask)->value('deliverables'), true))->toBe([$legacy])
            ->and(Schema::hasColumn('projects', 'test_command'))->toBeFalse();

        DB::table('tasks')->where('id', $expandedTask)->update([
            'status' => 'completed',
            'subtask_start_commit' => $sourceStart,
        ]);
        $splitTasks = DB::table('tasks')
            ->where('task_group_id', $open)
            ->where(function ($query) use ($expandedTask): void {
                $query->where('id', $expandedTask)->orWhere('title', 'like', 'Many legacy tests (continued %');
            })
            ->orderBy('position')
            ->get();
        $splitDeliverables = $splitTasks->map(static fn (object $task): array => json_decode((string) $task->deliverables, true, flags: JSON_THROW_ON_ERROR))->all();
        $allExpanded = array_merge(...$splitDeliverables);

        foreach ($splitDeliverables as $items) {
            $ids = array_column($items, 'id');
            expect(count($items))->toBeLessThanOrEqual(5)
                ->and(count($ids))->toBe(count(array_unique($ids)))
                ->and(collect($items)->every(static fn (array $item): bool => strlen($item['id']) <= 64 && preg_match('/\\A[a-z0-9]+(?:-[a-z0-9]+)*\\z/', $item['id']) === 1))->toBeTrue()
                ->and(Validator::make(['deliverables' => $items], (new UpdateTaskRequest)->rules())->passes())->toBeTrue();
        }

        expect($splitTasks)->toHaveCount(3)
            ->and($splitTasks->pluck('position')->all())->toBe([2, 3, 4])
            ->and(DB::table('tasks')->where('id', $laterTask)->value('position'))->toBe(5)
            ->and($splitTasks->slice(1)->every(static fn (object $task): bool => $task->continuation_of_task_id === $expandedTask))->toBeTrue()
            ->and(DB::table('tasks')->where('id', $expandedTask)->value('subtask_start_commit'))->toBe($sourceStart)
            ->and(count(array_filter($allExpanded, static fn (array $item): bool => $item['type'] === 'command')))->toBe(5)
            ->and(count(array_filter($allExpanded, static fn (array $item): bool => $item['type'] === 'file')))->toBe(5)
            ->and(array_values(array_filter($allExpanded, static fn (array $item): bool => $item['type'] === 'review')))->toBe([[
                'id' => 'repro-file', 'type' => 'review', 'description' => 'Keep this review proof.',
            ]])
            ->and($allExpanded[2]['id'])->toBe('repro-file-2')
            ->and($allExpanded[3]['id'])->toBe($longId)
            ->and(strlen($allExpanded[4]['id']))->toBeLessThanOrEqual(64);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('test_deliverables_migration');
    }
});
