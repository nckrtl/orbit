<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function assistance_kind_migration(): object
{
    config()->set('database.connections.assistance_kind', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'foreign_key_constraints' => true,
    ]);
    DB::setDefaultConnection('assistance_kind');
    $paths = array_values(array_filter(
        glob(database_path('migrations/*.php')) ?: [],
        static fn (string $path): bool => ! str_contains($path, 'add_assistance_kind_to_tasks'),
    ));
    Artisan::call('migrate', [
        '--database' => 'assistance_kind',
        '--path' => $paths,
        '--realpath' => true,
        '--force' => true,
    ]);

    return require database_path('migrations/2026_10_07_000001_add_assistance_kind_to_tasks.php');
}

it('classifies open blocked requests as direction and every other open request as failure', function (): void {
    $default = DB::getDefaultConnection();
    try {
        $migration = assistance_kind_migration();
        $now = now();
        $projectId = DB::table('projects')->insertGetId([
            'name' => 'Kind',
            'slug' => 'kind',
            'code' => 'KND',
            'type' => 'laravel-app',
            'repository_url' => 'git@example.test:kind.git',
            'repository_identity' => 'example.test/kind',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $insert = static function (array $values) use ($projectId, $now): int {
            return DB::table('tasks')->insertGetId([
                'project_id' => $projectId,
                'title' => 'Task',
                'brief' => 'Brief',
                'status' => 'running',
                'created_at' => $now,
                'updated_at' => $now,
                ...$values,
            ]);
        };
        $directionTask = $insert([
            'parent_id' => null,
            'assistance_requested' => true,
            'assistance_reason' => 'The implementer is blocked: stale task reason',
        ]);
        $implementer = $insert([
            'parent_id' => $directionTask,
            'position' => 1,
            'completion_attempt' => 2,
            'review_attempt' => 1,
            'assistance_requested' => true,
            'assistance_reason' => "The implementer is blocked: The mirror is down.\n\nQuestion: first\n\nQuestion: Which mirror should I use?",
        ]);
        $failureSubtask = $insert([
            'parent_id' => $directionTask,
            'position' => 2,
            'assistance_requested' => true,
            'assistance_reason' => 'The implementer thread failed.',
        ]);
        $reviewerTask = $insert([
            'parent_id' => null,
            'assistance_requested' => true,
            'assistance_reason' => 'old task reason',
        ]);
        $reviewer = $insert([
            'parent_id' => $reviewerTask,
            'position' => 1,
            'assistance_requested' => true,
            'assistance_reason' => 'The reviewer is blocked: The brief contradicts the ADR.',
        ]);
        $taskOnly = $insert([
            'parent_id' => null,
            'assistance_requested' => true,
            'assistance_reason' => 'The expected pull request closed without merging.',
        ]);
        $closed = $insert([
            'parent_id' => $taskOnly,
            'position' => 1,
            'assistance_requested' => false,
            'assistance_reason' => 'The implementer is blocked: Kept.\n\nQuestion: Should this stay closed?',
        ]);

        $migration->up();

        expect(DB::table('tasks')->where('id', $implementer)->value('assistance_kind'))->toBe('direction')
            ->and(DB::table('tasks')->where('id', $implementer)->value('assistance_question'))->toBe('Which mirror should I use?')
            ->and(DB::table('tasks')->where('id', $failureSubtask)->value('assistance_kind'))->toBe('failure')
            ->and(DB::table('tasks')->where('id', $failureSubtask)->value('assistance_question'))->toBeNull()
            ->and(DB::table('tasks')->where('id', $directionTask)->value('assistance_kind'))->toBe('direction')
            ->and(DB::table('tasks')->where('id', $directionTask)->value('assistance_question'))->toBe('Which mirror should I use?')
            ->and(DB::table('tasks')->where('id', $directionTask)->value('assistance_reason'))->toBe('The implementer is blocked: stale task reason')
            ->and(DB::table('tasks')->where('id', $reviewer)->value('assistance_kind'))->toBe('direction')
            ->and(DB::table('tasks')->where('id', $reviewer)->value('assistance_question'))->toBe('The brief contradicts the ADR.')
            ->and(DB::table('tasks')->where('id', $reviewerTask)->value('assistance_kind'))->toBe('direction')
            ->and(DB::table('tasks')->where('id', $reviewerTask)->value('assistance_question'))->toBe('The brief contradicts the ADR.')
            ->and(DB::table('tasks')->where('id', $reviewerTask)->value('assistance_reason'))->toBe('old task reason')
            ->and(DB::table('tasks')->where('id', $taskOnly)->value('assistance_kind'))->toBe('failure')
            ->and(DB::table('tasks')->where('id', $taskOnly)->value('assistance_question'))->toBeNull()
            ->and(DB::table('tasks')->where('id', $closed)->value('assistance_kind'))->toBeNull()
            ->and(DB::table('tasks')->where('id', $closed)->value('assistance_question'))->toBeNull()
            ->and(Schema::hasColumn('tasks', 'assistance_kind'))->toBeTrue()
            ->and(DB::table('task_questions')->where('subtask_id', $implementer)->value('asked_by'))->toBe('implementer')
            ->and(DB::table('task_questions')->where('subtask_id', $implementer)->value('cause'))->toBeNull()
            ->and(DB::table('task_questions')->where('subtask_id', $implementer)->value('status'))->toBe('escalated')
            ->and(DB::table('task_questions')->where('subtask_id', $implementer)->value('attempt'))->toBe(2)
            ->and(DB::table('task_questions')->where('subtask_id', $reviewer)->value('asked_by'))->toBe('reviewer')
            ->and(DB::table('task_questions')->where('subtask_id', $failureSubtask)->count())->toBe(0)
            ->and(DB::table('task_questions')->where('subtask_id', $closed)->count())->toBe(0)
            ->and(DB::table('tasks')->where('id', $directionTask)->value('questions'))->toBe(1)
            ->and(DB::table('tasks')->where('id', $directionTask)->value('escalations'))->toBe(1)
            ->and(DB::table('tasks')->where('id', $reviewerTask)->value('questions'))->toBe(1);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('assistance_kind');
    }
});

it('classifies the remaining open requests when a backfill attempt stops after the columns exist', function (): void {
    $default = DB::getDefaultConnection();
    $inject = false;
    $updates = 0;
    try {
        $migration = assistance_kind_migration();
        $now = now();
        $projectId = DB::table('projects')->insertGetId([
            'name' => 'Retry',
            'slug' => 'retry-kind',
            'code' => 'RTY',
            'type' => 'laravel-app',
            'repository_url' => 'git@example.test:retry.git',
            'repository_identity' => 'example.test/retry',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $insert = static function (array $values) use ($projectId, $now): int {
            return DB::table('tasks')->insertGetId([
                'project_id' => $projectId,
                'title' => 'Task',
                'brief' => 'Brief',
                'status' => 'running',
                'created_at' => $now,
                'updated_at' => $now,
                ...$values,
            ]);
        };
        $taskId = $insert([
            'parent_id' => null,
            'assistance_requested' => true,
            'assistance_reason' => 'stale task reason',
        ]);
        $directionId = $insert([
            'parent_id' => $taskId,
            'position' => 1,
            'assistance_requested' => true,
            'assistance_reason' => 'The implementer is blocked: The mirror is down.\n\nQuestion: Which mirror should I use?',
        ]);
        $failureId = $insert([
            'parent_id' => $taskId,
            'position' => 2,
            'assistance_requested' => true,
            'assistance_reason' => 'The implementer thread failed.',
        ]);
        $inject = true;
        DB::beforeExecuting(function (string $sql) use (&$inject, &$updates): void {
            if (! $inject || ! str_starts_with(strtolower(ltrim($sql)), 'update') || ! str_contains($sql, 'assistance_kind')) {
                return;
            }
            $updates++;
            if ($updates === 2) {
                throw new RuntimeException('injected backfill failure');
            }
        });

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'injected backfill failure');
        expect(DB::table('tasks')->where('id', $directionId)->value('assistance_kind'))->toBe('direction')
            ->and(DB::table('tasks')->where('id', $directionId)->value('assistance_question'))->toBe('Which mirror should I use?')
            ->and(DB::table('tasks')->where('id', $failureId)->value('assistance_kind'))->toBeNull()
            ->and(DB::table('tasks')->where('id', $taskId)->value('assistance_kind'))->toBeNull();

        $inject = false;
        $migration->up();

        expect(DB::table('tasks')->where('id', $failureId)->value('assistance_kind'))->toBe('failure')
            ->and(DB::table('tasks')->where('id', $failureId)->value('assistance_question'))->toBeNull()
            ->and(DB::table('tasks')->where('id', $taskId)->value('assistance_kind'))->toBe('direction')
            ->and(DB::table('tasks')->where('id', $taskId)->value('assistance_question'))->toBe('Which mirror should I use?')
            ->and(DB::table('tasks')->where('id', $taskId)->value('assistance_reason'))->toBe('stale task reason')
            ->and(DB::table('task_questions')->count())->toBe(1);

        $migration->up();

        expect(DB::table('task_questions')->count())->toBe(1)
            ->and(DB::table('tasks')->where('id', $directionId)->value('questions'))->toBe(1)
            ->and(DB::table('tasks')->where('id', $taskId)->value('escalations'))->toBe(1);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('assistance_kind');
    }
});

it('adds the question column when a previous attempt stopped after adding the kind column', function (): void {
    $default = DB::getDefaultConnection();
    $inject = false;
    try {
        $migration = assistance_kind_migration();
        $now = now();
        $projectId = DB::table('projects')->insertGetId([
            'name' => 'Retry column',
            'slug' => 'retry-column',
            'code' => 'RTC',
            'type' => 'laravel-app',
            'repository_url' => 'git@example.test:retry-column.git',
            'repository_identity' => 'example.test/retry-column',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $taskId = DB::table('tasks')->insertGetId([
            'project_id' => $projectId,
            'parent_id' => null,
            'title' => 'Task',
            'brief' => 'Brief',
            'status' => 'running',
            'assistance_requested' => true,
            'assistance_reason' => 'stale task reason',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $subtaskId = DB::table('tasks')->insertGetId([
            'project_id' => $projectId,
            'parent_id' => $taskId,
            'position' => 1,
            'title' => 'Subtask',
            'brief' => 'Brief',
            'status' => 'running',
            'assistance_requested' => true,
            'assistance_reason' => "The implementer is blocked: The mirror is down.\n\nQuestion: Which mirror should I use?",
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $inject = true;
        DB::beforeExecuting(function (string $sql) use (&$inject): void {
            if (! $inject || ! str_contains(strtolower($sql), 'assistance_question')) {
                return;
            }
            $inject = false;
            throw new RuntimeException('injected question column failure');
        });

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'injected question column failure');
        expect(Schema::hasColumn('tasks', 'assistance_kind'))->toBeTrue()
            ->and(Schema::hasColumn('tasks', 'assistance_question'))->toBeFalse();

        $migration->up();

        expect(Schema::hasColumn('tasks', 'assistance_question'))->toBeTrue()
            ->and(DB::table('tasks')->where('id', $subtaskId)->value('assistance_kind'))->toBe('direction')
            ->and(DB::table('tasks')->where('id', $subtaskId)->value('assistance_question'))->toBe('Which mirror should I use?')
            ->and(DB::table('tasks')->where('id', $taskId)->value('assistance_kind'))->toBe('direction')
            ->and(DB::table('tasks')->where('id', $taskId)->value('assistance_question'))->toBe('Which mirror should I use?');
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('assistance_kind');
    }
});
