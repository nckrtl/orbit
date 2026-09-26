<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSequenceException;
use App\Domain\Tasks\TaskStatus;
use App\Models\App as OrbitApp;
use App\Models\Task;
use App\Models\TaskGroup;

function predecessor_task_group(TaskStatus $predecessorStatus): array
{
    $app = OrbitApp::query()->create([
        'name' => 'Predecessor test',
        'slug' => 'predecessor-test',
        'repository_url' => 'git@example.test:predecessor-test.git',
        'default_branch' => 'main',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Sequential tasks',
        'brief' => 'Run tasks in order.',
        'status' => TaskGroupStatus::Running,
    ]);
    $predecessor = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'First task',
        'brief' => 'Complete the first task.',
        'status' => $predecessorStatus,
    ]);
    $successor = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 2,
        'title' => 'Second task',
        'brief' => 'Complete the second task.',
        'status' => TaskStatus::Todo,
    ]);

    return [$group, $predecessor, $successor];
}

it('considers a cancelled predecessor satisfied', function (): void {
    [, , $successor] = predecessor_task_group(TaskStatus::Cancelled);

    $started = app(TaskScheduler::class)->startTask($successor);

    expect($started->tasks->firstWhere('id', $successor->id)?->status)->toBe(TaskStatus::Running)
        ->and($started->tasks->firstWhere('position', 1)?->status)->toBe(TaskStatus::Cancelled);
});

it('does not reorder or skip a cancelled predecessor when starting its successor', function (): void {
    [$group, $predecessor, $successor] = predecessor_task_group(TaskStatus::Cancelled);

    app(TaskScheduler::class)->startTask($successor);
    $tasks = $group->tasks()->orderBy('position')->get();

    expect($tasks->pluck('position')->all())->toBe([1, 2])
        ->and($tasks->pluck('status')->all())->toBe([TaskStatus::Cancelled, TaskStatus::Running])
        ->and($predecessor->fresh()?->position)->toBe(1);
});

it('keeps a successor blocked while its predecessor is todo', function (): void {
    [, , $successor] = predecessor_task_group(TaskStatus::Todo);

    expect(fn () => app(TaskScheduler::class)->startTask($successor))
        ->toThrow(TaskSequenceException::class);

    expect($successor->fresh()?->status)->toBe(TaskStatus::Todo);
});

it('starts a successor when its predecessor has failed', function (): void {
    [, , $successor] = predecessor_task_group(TaskStatus::Failed);

    $started = app(TaskScheduler::class)->startTask($successor);

    expect($started->tasks->firstWhere('id', $successor->id)?->status)->toBe(TaskStatus::Running);
});
