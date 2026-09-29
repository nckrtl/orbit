<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskHierarchyException;
use App\Domain\Tasks\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('separates top-level tasks from subtasks and links parent and children', function (): void {
    $project = hierarchy_project();
    $task = Task::query()->create([
        'project_id' => $project->id,
        'title' => 'Feature',
        'brief' => 'Ship the feature.',
        'status' => TaskStatus::Backlog,
    ]);
    $task->children()->create(['position' => 2, 'title' => 'Second', 'brief' => 'Later.']);
    $first = $task->children()->create(['position' => 1, 'title' => 'First', 'brief' => 'Earlier.']);

    expect(Task::topLevel()->pluck('title')->all())->toBe(['Feature'])
        ->and(Task::query()->orderBy('position')->pluck('title')->all())->toBe(['First', 'Second'])
        ->and($first->parent?->is($task))->toBeTrue()
        ->and($first->task_group_id)->toBe($task->id)
        ->and($task->children()->pluck('title')->all())->toBe(['First', 'Second']);
});

it('refuses a subtask column on a top-level task', function (string $column, mixed $value): void {
    $task = Task::query()->create([
        'project_id' => hierarchy_project()->id,
        'title' => 'Feature',
        'brief' => 'Ship the feature.',
    ]);
    $task->setAttribute($column, $value);

    expect(fn () => $task->save())->toThrow(TaskHierarchyException::class, "cannot set {$column}");
})->with([
    'position' => ['position', 1],
    'deliverables' => ['deliverables', [['id' => 'note', 'type' => 'review', 'description' => 'Read it.']]],
    'fixup' => ['fixup_problem', 'The pull request failed.'],
]);

it('refuses a top-level column on a subtask', function (string $column, mixed $value): void {
    $task = Task::query()->create([
        'project_id' => hierarchy_project()->id,
        'title' => 'Feature',
        'brief' => 'Ship the feature.',
    ]);
    $subtask = $task->children()->create(['position' => 1, 'title' => 'First', 'brief' => 'Earlier.']);
    $subtask->setAttribute($column, $value);

    expect(fn () => $subtask->save())->toThrow(TaskHierarchyException::class, "cannot set {$column}");
})->with([
    'project' => ['project_id', 1],
    'pull request' => ['pr_url', 'https://example.test/pull/1'],
    'execution' => ['execution_mode', 'managed'],
]);

it('refuses a subtask that would have children', function (): void {
    $project = hierarchy_project();
    $task = Task::query()->create([
        'project_id' => $project->id,
        'title' => 'Feature',
        'brief' => 'Ship the feature.',
    ]);
    $subtask = $task->children()->create(['position' => 1, 'title' => 'First', 'brief' => 'Earlier.']);
    $other = Task::query()->create([
        'project_id' => $project->id,
        'title' => 'Other',
        'brief' => 'Another task.',
    ]);

    expect(fn () => $subtask->children()->create([
        'position' => 1,
        'title' => 'Nested',
        'brief' => 'Not allowed.',
    ]))->toThrow(TaskHierarchyException::class, 'cannot have subtasks');

    $task->forceFill([
        'parent_id' => $other->id,
        'project_id' => null,
        'taskable_type' => null,
        'taskable_id' => null,
        'pr_url' => null,
        'notify_coder' => null,
        'implementer_model' => null,
        'reviewer_model' => null,
        'reviewer_agent_thread_id' => null,
        'execution_mode' => null,
        'implementer_agent_driver' => null,
        'reviewer_agent_driver' => null,
        'agent_unavailable_since' => null,
        'agent_unavailable_notified_at' => null,
        'reserved_at' => null,
    ]);

    expect(fn () => $task->save())->toThrow(TaskHierarchyException::class, 'cannot have subtasks');
});

function hierarchy_project(): Project
{
    return Project::query()->create([
        'name' => 'Hierarchy',
        'slug' => 'hierarchy-'.bin2hex(random_bytes(4)),
        'repository_url' => 'git@example.test:hierarchy.git',
    ]);
}
