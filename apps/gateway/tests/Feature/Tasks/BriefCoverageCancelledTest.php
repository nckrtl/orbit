<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubPullRequestCommit;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskRunPullRequest;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Infrastructure\Tasks\Jev;
use App\Infrastructure\Tasks\LaravelAiTaskBriefCoverage;
use App\Models\App as OrbitApp;
use App\Models\JevDecision;
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

it('labels a false negative from a pull request observed merged and records both snapshots', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    Classification::fake([[
        'subtask_'.$completed->id => new BooleanAnswer(0.2),
    ]])->preventStrayClassifications();

    app(LaravelAiTaskBriefCoverage::class)->missing($group, cancelled_brief_coverage_pull_request(), 81, ['Orders export as CSV.']);
    $mergedBody = "Summary.\n\n## Changes\n\n- Orders export as CSV.\n\n## Breaking changes\nNone.\n";
    $merge = new TaskPullRequestHealth(
        state: 'merged',
        headSha: 'different-head-sha',
        pullRequestNumber: 42,
        mergeBody: $mergedBody,
        mergeSha: 'actual-merge-sha',
        mergedAt: '2026-10-01T10:00:00Z',
        mergeCommits: [new GitHubPullRequestCommit('audit-sha', "Audit changes\n\nBuild: 17", '2026-10-01T09:30:00Z')],
    );
    $group->update(['status' => 'settling', 'execution_mode' => 'managed', 'pr_url' => 'https://github.com/acme/shop/pull/42']);
    app()->instance(TaskPullRequestWatcher::class, new class($merge) implements TaskPullRequestWatcher
    {
        public function __construct(private TaskPullRequestHealth $merge) {}

        public function status(TaskGroup $group): ?string
        {
            return 'merged';
        }

        public function health(TaskGroup $group): ?TaskPullRequestHealth
        {
            return $this->merge;
        }
    });
    app(TaskExtensionState::class)->enable();
    app(TaskScheduler::class)->tick();

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Completed);
    $decision = JevDecision::query()->sole();
    $label = $decision->labels['questions']['subtask_'.$completed->id];
    expect($decision->approval_changes)->toBe(['Orders export as CSV.'])
        ->and($decision->approval_changes_digest)->toBe(hash('sha256', json_encode(['Orders export as CSV.'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)))
        ->and($decision->merge_changes)->toBe(['Orders export as CSV.'])
        ->and($decision->merge_changes_digest)->toBe(hash('sha256', json_encode(['Orders export as CSV.'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)))
        ->and($decision->merge_body_digest)->toBe(hash('sha256', $mergedBody))
        ->and($decision->merged_pull_request_number)->toBe(42)
        ->and($decision->merge_commit_sha)->toBe('actual-merge-sha')
        ->and($decision->merged_at)->toBe('2026-10-01T10:00:00Z')
        ->and($decision->merge_history_complete)->toBeTrue()
        ->and($decision->merge_commit_history)->toBe([['sha' => 'audit-sha', 'committed_at' => '2026-10-01T09:30:00Z', 'trailers' => ['Build: 17']]])
        ->and($decision->input_state['pull_request']['changes'])->toBe(['Orders export as CSV.'])
        ->and($label['label'])->toBe('false_negative')
        ->and($label['source']['rule'])->toBe('brief_coverage_v1')
        ->and($label['source']['task_id'])->toBe($completed->id)
        ->and($label['source']['approval_comment_id'])->toBe(81)
        ->and($label['source']['approval_changes_digest'])->toBe(hash('sha256', json_encode(['Orders export as CSV.'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)))
        ->and($label['source']['pull_request_number'])->toBe(42)
        ->and($label['source']['merge_sha'])->toBe('actual-merge-sha')
        ->and($label['source']['merged_at'])->toBe('2026-10-01T10:00:00Z')
        ->and($label['source']['matching_change_line'])->toBe('Orders export as CSV.');
});

it('does not treat breaking-change lines as coverage when the Changes section is empty', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.2)]]);
    app(LaravelAiTaskBriefCoverage::class)->missing($group, cancelled_brief_coverage_pull_request(), 80, ['Orders export as CSV.']);
    Jev::labelMergedCoverage($group, new TaskPullRequestHealth(
        state: 'merged', pullRequestNumber: 42,
        mergeBody: "## Changes\n\n## Breaking changes\n\n- Orders export as CSV.\n",
        mergeSha: 'merge-sha', mergedAt: '2026-10-01T10:00:00Z', mergeCommits: [],
    ));

    $decision = JevDecision::query()->sole();
    expect($decision->merge_changes)->toBe([])
        ->and($decision->labels['questions']['subtask_'.$completed->id]['label'])->toBe('correct');
});

it('leaves labels unknown when merge evidence is missing and uses the changed merge snapshot when known', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.2)]]);
    app(LaravelAiTaskBriefCoverage::class)->missing($group, cancelled_brief_coverage_pull_request(), 82, ['Orders export as CSV.']);
    Jev::labelMergedCoverage($group, new TaskPullRequestHealth(state: 'merged', pullRequestNumber: 42, mergeCommits: []));
    expect(JevDecision::query()->sole()->labels)->toBeNull();

    Jev::labelMergedCoverage($group, new TaskPullRequestHealth(
        state: 'merged',
        pullRequestNumber: 42,
        mergeBody: "Summary.\n\n## Changes\n\n- Export unrelated data.\n",
        mergeSha: 'merge-sha',
        mergedAt: '2026-10-01T10:00:00Z',
        mergeCommits: [],
    ));
    expect(JevDecision::query()->sole()->labels['questions']['subtask_'.$completed->id]['label'])->toBe('correct');
});

it('labels correct answers, call outcomes, and leaves failures unlabeled', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.97)]]);
    app(LaravelAiTaskBriefCoverage::class)->missing($group, cancelled_brief_coverage_pull_request(), 83, ['Orders export as CSV.']);
    $merge = new TaskPullRequestHealth(
        state: 'merged',
        pullRequestNumber: 42,
        mergeBody: "Summary.\n\n## Changes\n\n- Unrelated change.\n",
        mergeSha: 'merge-sha',
        mergedAt: '2026-10-01T10:00:00Z',
        mergeCommits: [],
    );
    Jev::labelMergedCoverage($group, $merge);

    $decision = JevDecision::query()->sole();
    expect($decision->labels['questions']['subtask_'.$completed->id]['label'])->toBe('false_positive')
        ->and($decision->labels['call']['label'])->toBe('correct');

    $failedDecision = JevDecision::query()->create([
        'purpose' => 'brief_coverage', 'task_group_id' => $group->id, 'task_ids' => [$completed->id],
        'questions' => [], 'input_state' => [], 'answers' => null,
    ]);
    Jev::labelMergedCoverage($group, $merge);
    expect($failedDecision->fresh()->labels)->toBeNull();
});

it('normalizes compatibility characters with Unicode NFKC before matching', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    $completed->update(['title' => 'Ｅｘｐｏｒｔ']);
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.2)]]);
    app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskRunPullRequest('Summary.', ['Export'], []), 89, ['Export']);
    Jev::labelMergedCoverage($group, new TaskPullRequestHealth(state: 'merged', pullRequestNumber: 42, mergeBody: "## Changes\n\n- Export\n", mergeSha: 'merge-sha', mergedAt: '2026-10-01T10:00:00Z', mergeCommits: []));

    expect(JevDecision::query()->sole()->labels['questions']['subtask_'.$completed->id]['label'])->toBe('false_negative');
});

it('does not assign a call-level label to a mixed-answer coverage call', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    $other = Task::query()->create(['task_group_id' => $group->id, 'position' => 4, 'title' => 'Route', 'brief' => 'Add a route.', 'status' => TaskStatus::Completed]);
    Classification::fake([
        ['subtask_'.$completed->id => new BooleanAnswer(0.97), 'subtask_'.$other->id => new BooleanAnswer(0.2)],
    ]);
    app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskRunPullRequest('Summary.', ['Export', 'Route'], []), 88, ['Export', 'Route']);
    Jev::labelMergedCoverage($group, new TaskPullRequestHealth(
        state: 'merged', pullRequestNumber: 42, mergeBody: "## Changes\n\n- Export\n- Route\n",
        mergeSha: 'merge-sha', mergedAt: '2026-10-01T10:00:00Z', mergeCommits: [],
    ));

    expect(JevDecision::query()->sole()->labels)->not->toHaveKey('call');
});

it('labels a covered answer false positive when a later merged commit has its exact coverage fix trailer', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.97)]]);
    app(LaravelAiTaskBriefCoverage::class)->missing($group, cancelled_brief_coverage_pull_request(), 84, ['Orders export as CSV.']);
    Jev::labelMergedCoverage($group, new TaskPullRequestHealth(
        state: 'merged', pullRequestNumber: 42,
        mergeBody: "## Changes\n\n- Orders export as CSV.\n",
        mergeSha: 'merge-sha', mergedAt: '2026-10-01T10:00:00Z',
        mergeCommits: [new GitHubPullRequestCommit('fix-sha', "Fix export\n\nOrbit-Coverage-Fix: task-{$completed->id}", now()->addMinute()->toIso8601String())],
    ));

    $label = JevDecision::query()->sole()->labels['questions']['subtask_'.$completed->id];
    expect($label['label'])->toBe('false_positive')
        ->and($label['source']['coverage_fix_commit_sha'])->toBe('fix-sha');
});

it('uses normalized title uniqueness and Unicode case folding before labeling', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    $completed->update(['title' => 'Straße']);
    Task::query()->create(['task_group_id' => $group->id, 'position' => 4, 'title' => 'STRASSE', 'brief' => 'Same after normalization.', 'status' => TaskStatus::Completed]);
    Classification::fake([
        ['subtask_'.$completed->id => new BooleanAnswer(0.2), 'subtask_'.Task::query()->where('title', 'STRASSE')->value('id') => new BooleanAnswer(0.2)],
    ]);
    app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskRunPullRequest('Summary.', ['Straße'], []), 85, ['Straße']);
    Jev::labelMergedCoverage($group, new TaskPullRequestHealth(state: 'merged', pullRequestNumber: 42, mergeBody: "## Changes\n\n- STRASSE\n", mergeSha: 'merge-sha', mergedAt: '2026-10-01T10:00:00Z', mergeCommits: []));

    expect(JevDecision::query()->sole()->labels)->toBeNull();
});

it('case-folds Unicode titles when matching merge change lines', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    $completed->update(['title' => 'Straße']);
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.2)]]);
    app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskRunPullRequest('Summary.', ['Fix STRASSE behavior'], []), 86, ['Fix STRASSE behavior']);
    Jev::labelMergedCoverage($group, new TaskPullRequestHealth(state: 'merged', pullRequestNumber: 42, mergeBody: "## Changes\n\n- Fix STRASSE behavior\n", mergeSha: 'merge-sha', mergedAt: '2026-10-01T10:00:00Z', mergeCommits: []));

    expect(JevDecision::query()->sole()->labels['questions']['subtask_'.$completed->id]['label'])->toBe('false_negative');
});

it('does not label from an unmerged PR or unavailable history and ignores pre-call and out-of-group fixes', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.97)]]);
    app(LaravelAiTaskBriefCoverage::class)->missing($group, cancelled_brief_coverage_pull_request(), 87, ['Orders export as CSV.']);
    $decision = JevDecision::query()->sole();
    $history = [
        new GitHubPullRequestCommit('pre-call', "Fix\n\nOrbit-Coverage-Fix: task-{$completed->id}", now()->subMinute()->toIso8601String()),
        new GitHubPullRequestCommit('other-task', "Fix\n\nOrbit-Coverage-Fix: task-999999", now()->addMinute()->toIso8601String()),
    ];
    Jev::labelMergedCoverage($group, new TaskPullRequestHealth(state: 'open', pullRequestNumber: 42, mergeBody: "## Changes\n\n- Orders export as CSV.\n", mergeSha: 'not-merged', mergedAt: '2026-10-01T10:00:00Z', mergeCommits: $history));
    expect($decision->fresh()->labels)->toBeNull();

    Jev::labelMergedCoverage($group, new TaskPullRequestHealth(state: 'merged', pullRequestNumber: 42, mergeBody: "## Changes\n\n- Orders export as CSV.\n", mergeSha: 'merge-sha', mergedAt: '2026-10-01T10:00:00Z', mergeCommits: null));
    expect($decision->fresh()->labels)->toBeNull();

    Jev::labelMergedCoverage($group, new TaskPullRequestHealth(state: 'merged', pullRequestNumber: 42, mergeBody: "## Changes\n\n- Orders export as CSV.\n", mergeSha: 'merge-sha', mergedAt: '2026-10-01T10:00:00Z', mergeCommits: $history));
    expect($decision->fresh()->labels['questions']['subtask_'.$completed->id]['label'])->toBe('correct');
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
