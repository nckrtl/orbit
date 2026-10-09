<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskHierarchyException;
use App\Domain\Tasks\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('separates top-level tasks from subtasks and links parent and children', function (): void {
    $project = hierarchy_project();
    $task = Task::query()->create([
        'project_id' => $project->id,
        'title' => 'Feature',
        'brief' => 'Ship the feature.',
        'status' => TaskGroupStatus::Backlog,
    ]);
    $task->children()->create(['position' => 2, 'title' => 'Second', 'brief' => 'Later.']);
    $first = $task->children()->create(['position' => 1, 'title' => 'First', 'brief' => 'Earlier.']);

    expect(Task::topLevel()->pluck('title')->all())->toBe(['Feature'])
        ->and(Task::query()->orderBy('position')->pluck('title')->all())->toBe(['First', 'Second'])
        ->and($first->parent?->is($task))->toBeTrue()
        ->and($first->parent_id)->toBe($task->id)
        ->and($task->children()->pluck('title')->all())->toBe(['First', 'Second'])
        ->and($task->implementer_agent_driver)->toBe('pi')
        ->and($task->reviewer_agent_driver)->toBe('pi');
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
        'preview' => null,
    ]);

    expect(fn () => $task->save())->toThrow(TaskHierarchyException::class, 'cannot have subtasks');
});

it('decides the level from a loaded parent_id for topLevel() and a partial select', function (): void {
    $task = Task::topLevel()->create([
        'project_id' => hierarchy_project()->id,
        'title' => 'Feature',
        'brief' => 'Ship the feature.',
        'status' => TaskGroupStatus::Backlog,
    ]);
    $subtask = $task->children()->create(['position' => 1, 'title' => 'First', 'brief' => 'Earlier.', 'status' => TaskStatus::Todo]);
    $partialTask = Task::topLevel()->whereKey($task->id)->firstOrFail(['id', 'status']);
    $partialSubtask = Task::query()->whereKey($subtask->id)->firstOrFail(['id', 'status']);

    expect(array_key_exists('parent_id', $partialTask->getAttributes()))->toBeFalse()
        ->and(array_key_exists('parent_id', $partialSubtask->getAttributes()))->toBeFalse()
        ->and($task->isTopLevel())->toBeTrue()
        ->and($task->status)->toBeInstanceOf(TaskGroupStatus::class)
        ->and($partialTask->status)->toBeInstanceOf(TaskGroupStatus::class)
        ->and($partialTask->isTopLevel())->toBeTrue()
        ->and($subtask->status)->toBeInstanceOf(TaskStatus::class)
        ->and($partialSubtask->status)->toBeInstanceOf(TaskStatus::class)
        ->and($partialSubtask->isTopLevel())->toBeFalse();
});

it('does not guess a level when parent_id was never loaded', function (): void {
    $task = new Task;
    $task->setRawAttributes(['status' => 'todo']);

    expect(fn () => $task->status)->toThrow(LogicException::class, 'parent_id');
});

it('rejects a top-level-only status on a subtask', function (string $status): void {
    $task = Task::query()->create([
        'project_id' => hierarchy_project()->id,
        'title' => 'Feature',
        'brief' => 'Ship the feature.',
    ]);
    $subtask = $task->children()->create(['position' => 1, 'title' => 'First', 'brief' => 'Earlier.']);

    expect(fn () => $subtask->forceFill(['status' => $status])->save())->toThrow(ValueError::class);

    DB::table('tasks')->where('id', $subtask->id)->update(['status' => $status]);
    $loaded = Task::query()->findOrFail($subtask->id);

    expect(fn () => $loaded->status)->toThrow(ValueError::class);
})->with(['backlog', 'settling']);

function hierarchy_project(): Project
{
    return Project::query()->create([
        'name' => 'Hierarchy',
        'slug' => 'hierarchy-'.bin2hex(random_bytes(4)),
        'repository_url' => 'git@example.test:hierarchy.git',
        'apps' => fixture_apps(null),
    ]);
}
