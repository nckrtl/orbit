<?php

declare(strict_types=1);

use App\Models\Project;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('clears assistance on every ended task and leaves every other task alone', function (): void {
    $default = DB::getDefaultConnection();
    config(['database.connections.ended_assistance' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('ended_assistance');

    try {
        $paths = array_values(array_filter(
            glob(database_path('migrations/*.php')) ?: [],
            static fn (string $path): bool => ! str_contains($path, 'clear_assistance_on_ended_tasks'),
        ));
        Artisan::call('migrate', [
            '--database' => 'ended_assistance',
            '--path' => $paths,
            '--realpath' => true,
            '--force' => true,
        ]);

        $project = Project::query()->create([
            'name' => 'Ended assistance',
            'slug' => 'ended-assistance',
            'repository_url' => 'git@example.test:ended-assistance.git',
            'default_branch' => 'main',
            'apps' => fixture_apps(null),
        ]);
        $stamp = '2026-10-01 12:00:00';
        $insert = function (array $row) use ($project, $stamp): int {
            return (int) DB::table('tasks')->insertGetId([
                'project_id' => $project->id,
                'parent_id' => $row['parent_id'] ?? null,
                'position' => $row['position'] ?? null,
                'title' => $row['title'],
                'brief' => 'Stored before the flag was cleared.',
                'status' => $row['status'],
                'assistance_requested' => $row['flag'],
                'assistance_reason' => $row['reason'],
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
        };
        $completedGroup = $insert(['title' => 'Completed group', 'status' => 'completed', 'flag' => true, 'reason' => 'Hold the group.']);
        $cancelledGroup = $insert(['title' => 'Cancelled group', 'status' => 'cancelled', 'flag' => true, 'reason' => null]);
        $clearGroup = $insert(['title' => 'Already clear', 'status' => 'completed', 'flag' => false, 'reason' => 'Kept history.']);
        $running = $insert(['title' => 'Running group', 'status' => 'running', 'flag' => true, 'reason' => 'Still blocked.']);
        $settling = $insert(['title' => 'Settling group', 'status' => 'settling', 'flag' => true, 'reason' => 'Waiting on the pull request.']);
        $failed = $insert(['title' => 'Failed group', 'status' => 'failed', 'flag' => true, 'reason' => 'The check failed.']);
        $completedSubtask = $insert(['title' => 'Completed subtask', 'status' => 'completed', 'flag' => true, 'reason' => 'Hold the subtask.', 'parent_id' => $completedGroup, 'position' => 1]);
        $cancelledSubtask = $insert(['title' => 'Cancelled subtask', 'status' => 'cancelled', 'flag' => true, 'reason' => 'Stopped early.', 'parent_id' => $cancelledGroup, 'position' => 1]);
        $openSubtask = $insert(['title' => 'Open subtask', 'status' => 'todo', 'flag' => true, 'reason' => 'Still open.', 'parent_id' => $running, 'position' => 1]);

        $migration = require glob(database_path('migrations/*clear_assistance_on_ended_tasks.php'))[0];
        $migration->up();

        $row = static fn (int $id): object => DB::table('tasks')->where('id', $id)->first();
        $completed = $row($completedGroup);
        $cancelled = $row($cancelledGroup);
        $clear = $row($clearGroup);
        $stillRunning = $row($running);
        $stillSettling = $row($settling);
        $stillFailed = $row($failed);
        $doneSubtask = $row($completedSubtask);
        $stoppedSubtask = $row($cancelledSubtask);
        $waitingSubtask = $row($openSubtask);

        expect((bool) $completed->assistance_requested)->toBeFalse()
            ->and($completed->assistance_reason)->toBe('Hold the group.')
            ->and($completed->status)->toBe('completed')
            ->and((bool) $cancelled->assistance_requested)->toBeFalse()
            ->and($cancelled->assistance_reason)->toBeNull()
            ->and($cancelled->status)->toBe('cancelled')
            ->and((bool) $doneSubtask->assistance_requested)->toBeFalse()
            ->and($doneSubtask->assistance_reason)->toBe('Hold the subtask.')
            ->and($doneSubtask->status)->toBe('completed')
            ->and((bool) $stoppedSubtask->assistance_requested)->toBeFalse()
            ->and($stoppedSubtask->assistance_reason)->toBe('Stopped early.')
            ->and($stoppedSubtask->status)->toBe('cancelled')
            ->and((bool) $clear->assistance_requested)->toBeFalse()
            ->and($clear->assistance_reason)->toBe('Kept history.')
            ->and($clear->updated_at)->toBe($stamp)
            ->and((bool) $stillRunning->assistance_requested)->toBeTrue()
            ->and($stillRunning->assistance_reason)->toBe('Still blocked.')
            ->and($stillRunning->status)->toBe('running')
            ->and($stillRunning->updated_at)->toBe($stamp)
            ->and((bool) $stillSettling->assistance_requested)->toBeTrue()
            ->and($stillSettling->assistance_reason)->toBe('Waiting on the pull request.')
            ->and($stillSettling->updated_at)->toBe($stamp)
            ->and((bool) $stillFailed->assistance_requested)->toBeTrue()
            ->and($stillFailed->assistance_reason)->toBe('The check failed.')
            ->and($stillFailed->updated_at)->toBe($stamp)
            ->and((bool) $waitingSubtask->assistance_requested)->toBeTrue()
            ->and($waitingSubtask->assistance_reason)->toBe('Still open.')
            ->and($waitingSubtask->status)->toBe('todo')
            ->and($waitingSubtask->updated_at)->toBe($stamp);

        $migration->down();

        expect((bool) $row($completedGroup)->assistance_requested)->toBeFalse()
            ->and($row($completedGroup)->assistance_reason)->toBe('Hold the group.')
            ->and((bool) $row($running)->assistance_requested)->toBeTrue();
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('ended_assistance');
    }
});
