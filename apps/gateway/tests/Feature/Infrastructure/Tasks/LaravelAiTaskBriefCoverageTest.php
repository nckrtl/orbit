<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskRunPullRequest;
use App\Domain\Tasks\TaskSessionClassificationException;
use App\Infrastructure\Tasks\LaravelAiTaskBriefCoverage;
use App\Models\App as OrbitApp;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;

/** @return array{TaskGroup, Task, Task} */
function coverage_group(): array
{
    $app = OrbitApp::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main']);
    $group = TaskGroup::query()->create(['app_id' => $app->id, 'title' => 'Export orders', 'brief' => 'Export orders as CSV and add a download route.', 'status' => 'reviewing']);
    $models = Task::query()->create(['task_group_id' => $group->id, 'position' => 1, 'title' => 'Export', 'brief' => 'Write the CSV export.', 'status' => 'completed']);
    $routes = Task::query()->create(['task_group_id' => $group->id, 'position' => 2, 'title' => 'Route', 'brief' => 'Add the download route.', 'status' => 'reviewing']);

    return [$group, $models, $routes];
}

function coverage_pull_request(): TaskRunPullRequest
{
    return new TaskRunPullRequest('Adds an order export.', ['Orders export as CSV.'], []);
}

it('names each subtask that no listed change delivers', function (): void {
    [$group, $export, $route] = coverage_group();
    Classification::fake([[
        'subtask_'.$export->id => new BooleanAnswer(0.97),
        'subtask_'.$route->id => new BooleanAnswer(0.2),
    ]]);

    expect(app(LaravelAiTaskBriefCoverage::class)->missing($group, coverage_pull_request()))->toBe(['Route']);
    Classification::assertClassified(static fn (ClassificationPrompt $prompt): bool => is_array($prompt->state)
        && $prompt->state['group_brief'] === 'Export orders as CSV and add a download route.'
        && $prompt->state['pull_request'] === ['summary' => 'Adds an order export.', 'changes' => ['Orders export as CSV.'], 'breaking' => []]
        && array_column($prompt->state['subtasks'], 'title') === ['Export', 'Route']);
});

it('treats an answer below the threshold as missing', function (): void {
    [$group, $export, $route] = coverage_group();
    config()->set('orbit.tasks.jev_confidence_threshold', 0.9);
    Classification::fake([[
        'subtask_'.$export->id => new BooleanAnswer(0.85),
        'subtask_'.$route->id => new BooleanAnswer(0.95),
    ]]);

    expect(app(LaravelAiTaskBriefCoverage::class)->missing($group, coverage_pull_request()))->toBe(['Export']);
});

it('reports a failed or incomplete Jev answer as a classification failure', function (): void {
    [$group] = coverage_group();
    config()->set('ai.providers.typesafe.key', 'typesafe-test-key');
    Classification::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(fn () => app(LaravelAiTaskBriefCoverage::class)->missing($group, coverage_pull_request()))
        ->toThrow(TaskSessionClassificationException::class, 'TypeSafe Jev request failed (ConnectionException).');
});
