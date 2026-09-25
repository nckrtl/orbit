<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskRunPullRequest;
use App\Domain\Tasks\TaskStatus;
use App\Infrastructure\Tasks\LaravelAiTaskBriefCoverage;
use App\Models\App as OrbitApp;
use App\Models\Task;
use App\Models\TaskGroup;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;

/** @return array{TaskGroup, Task, Task, Task} */
function cancelled_brief_coverage_group(): array
{
    $app = OrbitApp::query()->create([
        'name' => 'Shop',
        'slug' => 'shop',
        'repository_url' => 'git@github.com:acme/shop.git',
        'default_branch' => 'main',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Export orders',
        'brief' => 'Export orders as CSV.',
        'status' => 'reviewing',
    ]);
    $cancelled = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'Cancelled report',
        'brief' => 'Add a report that was cancelled.',
        'status' => TaskStatus::Cancelled,
    ]);
    $failed = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 2,
        'title' => 'Failed report',
        'brief' => 'Add a report that failed.',
        'status' => TaskStatus::Failed,
    ]);
    $completed = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 3,
        'title' => 'Export',
        'brief' => 'Write the CSV export.',
        'status' => TaskStatus::Completed,
    ]);

    return [$group, $cancelled, $failed, $completed];
}

function cancelled_brief_coverage_pull_request(): TaskRunPullRequest
{
    return new TaskRunPullRequest('Adds an order export.', ['Orders export as CSV.'], []);
}

it('skips cancelled brief coverage when the remaining subtasks are covered', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    Classification::fake([[
        'subtask_'.$cancelled->id => new BooleanAnswer(0.1),
        'subtask_'.$failed->id => new BooleanAnswer(0.1),
        'subtask_'.$completed->id => new BooleanAnswer(0.97),
    ]])->preventStrayClassifications();

    expect(app(LaravelAiTaskBriefCoverage::class)->missing($group, cancelled_brief_coverage_pull_request()))->toBe([]);
    Classification::assertClassified(static fn (ClassificationPrompt $prompt): bool => array_column($prompt->state['subtasks'], 'title') === ['Export']
        && array_keys($prompt->questions) === ['subtask_'.$completed->id]);
});

it('still reports an unmatched active subtask', function (TaskStatus $status): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    $active = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 4,
        'title' => 'Active route',
        'brief' => 'Add an active route.',
        'status' => $status,
    ]);
    Classification::fake([[
        'subtask_'.$cancelled->id => new BooleanAnswer(0.1),
        'subtask_'.$failed->id => new BooleanAnswer(0.1),
        'subtask_'.$completed->id => new BooleanAnswer(0.97),
        'subtask_'.$active->id => new BooleanAnswer(0.2),
    ]])->preventStrayClassifications();

    expect(app(LaravelAiTaskBriefCoverage::class)->missing($group, cancelled_brief_coverage_pull_request()))->toBe(['Active route']);
})->with([
    'todo' => TaskStatus::Todo,
    'completed' => TaskStatus::Completed,
]);
