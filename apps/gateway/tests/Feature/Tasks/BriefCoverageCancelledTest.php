<?php

declare(strict_types=1);

use App\Domain\Tasks\BriefCoverageLabeler;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnPullRequest;
use App\Infrastructure\Tasks\JevRecorder;
use App\Infrastructure\Tasks\LaravelAiTaskBriefCoverage;
use App\Models\JevDecision;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;

use function Pest\Laravel\mock;

/** @return array{Task, Task, Task, Task} */
function cancelled_brief_coverage_group(): array
{
    $project = Project::query()->create([
        'name' => 'Shop',
        'slug' => 'shop',
        'repository_url' => 'git@github.com:acme/shop.git',
        'default_branch' => 'main',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Export orders',
        'brief' => 'Export orders as CSV.',
        'status' => 'reviewing',
    ]);
    $cancelled = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Cancelled report',
        'brief' => 'Add a report that was cancelled.',
        'status' => TaskStatus::Cancelled,
    ]);
    $failed = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 2,
        'title' => 'Failed report',
        'brief' => 'Add a report that failed.',
        'status' => TaskStatus::Failed,
    ]);
    $completed = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 3,
        'title' => 'Export',
        'brief' => 'Write the CSV export.',
        'status' => TaskStatus::Completed,
    ]);

    return [$group, $cancelled, $failed, $completed];
}

function cancelled_brief_coverage_pull_request(): TaskTurnPullRequest
{
    return new TaskTurnPullRequest('Adds an order export.', ['Orders export as CSV.'], []);
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
    );
    $group->update(['status' => 'settling', 'execution_mode' => 'managed', 'pr_url' => 'https://github.com/acme/shop/pull/42']);
    app()->instance(TaskPullRequestWatcher::class, new class($merge) implements TaskPullRequestWatcher
    {
        public function __construct(private TaskPullRequestHealth $merge) {}

        public function status(Task $group): ?string
        {
            return 'merged';
        }

        public function health(Task $group): ?TaskPullRequestHealth
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
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.2)]])->preventStrayClassifications();
    app(LaravelAiTaskBriefCoverage::class)->missing($group, cancelled_brief_coverage_pull_request(), 80, ['Orders export as CSV.']);
    app(BriefCoverageLabeler::class)->label($group, new TaskPullRequestHealth(
        state: 'merged', pullRequestNumber: 42,
        mergeBody: "## Changes\n\n## Breaking changes\n\n- Orders export as CSV.\n",
        mergeSha: 'merge-sha', mergedAt: '2026-10-01T10:00:00Z',
    ));

    $decision = JevDecision::query()->sole();
    expect($decision->merge_changes)->toBe([])
        ->and($decision->labels)->toBeNull();
});

it('leaves labels unknown when merge evidence is missing and uses the changed merge snapshot when known', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.2)]])->preventStrayClassifications();
    app(LaravelAiTaskBriefCoverage::class)->missing($group, cancelled_brief_coverage_pull_request(), 82, ['Orders export as CSV.']);
    app(BriefCoverageLabeler::class)->label($group, new TaskPullRequestHealth(state: 'merged', pullRequestNumber: 42));
    expect(JevDecision::query()->sole()->labels)->toBeNull();

    app(BriefCoverageLabeler::class)->label($group, new TaskPullRequestHealth(
        state: 'merged',
        pullRequestNumber: 42,
        mergeBody: "Summary.\n\n## Changes\n\n- Export unrelated data.\n",
        mergeSha: 'merge-sha',
        mergedAt: '2026-10-01T10:00:00Z',
    ));
    expect(JevDecision::query()->sole()->labels)->toBeNull();
});

it('labels correct answers, call outcomes, and leaves failures unlabeled', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.97)]])->preventStrayClassifications();
    app(LaravelAiTaskBriefCoverage::class)->missing($group, cancelled_brief_coverage_pull_request(), 83, ['Orders export as CSV.']);
    $merge = new TaskPullRequestHealth(
        state: 'merged',
        pullRequestNumber: 42,
        mergeBody: "Summary.\n\n## Changes\n\n- Unrelated change.\n",
        mergeSha: 'merge-sha',
        mergedAt: '2026-10-01T10:00:00Z',
    );
    app(BriefCoverageLabeler::class)->label($group, $merge);

    $decision = JevDecision::query()->sole();
    expect($decision->labels['questions'] ?? [])->toBe([])
        ->and($decision->labels['call']['label'])->toBe('correct');

    $failedDecision = JevDecision::query()->create([
        'purpose' => 'brief_coverage', 'call_started_at' => now()->toIso8601String(), 'task_group_id' => $group->id, 'task_ids' => [$completed->id],
        'questions' => [], 'input_state' => [], 'answers' => null,
    ]);
    app(BriefCoverageLabeler::class)->label($group, $merge);
    expect($failedDecision->fresh()->labels)->toBeNull();
});

it('normalizes compatibility characters with Unicode NFKC before matching', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    $completed->update(['title' => 'Ｅｘｐｏｒｔ']);
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.2)]])->preventStrayClassifications();
    app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskTurnPullRequest('Summary.', ['Export'], []), 89, ['Export']);
    app(BriefCoverageLabeler::class)->label($group, new TaskPullRequestHealth(state: 'merged', pullRequestNumber: 42, mergeBody: "## Changes\n\n- Export\n", mergeSha: 'merge-sha', mergedAt: '2026-10-01T10:00:00Z'));

    expect(JevDecision::query()->sole()->labels['questions']['subtask_'.$completed->id]['label'])->toBe('false_negative');
});

it('does not assign a call-level label to a mixed-answer coverage call', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    $other = Task::query()->create(['parent_id' => $group->id, 'position' => 4, 'title' => 'Route', 'brief' => 'Add a route.', 'status' => TaskStatus::Completed]);
    Classification::fake([
        ['subtask_'.$completed->id => new BooleanAnswer(0.97), 'subtask_'.$other->id => new BooleanAnswer(0.2)],
    ])->preventStrayClassifications();
    app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskTurnPullRequest('Summary.', ['Adds Export', 'Adds Route'], []), 88, ['Adds Export', 'Adds Route']);
    app(BriefCoverageLabeler::class)->label($group, new TaskPullRequestHealth(
        state: 'merged', pullRequestNumber: 42, mergeBody: "## Changes\n\n- Adds Export\n- Adds Route\n",
        mergeSha: 'merge-sha', mergedAt: '2026-10-01T10:00:00Z',
    ));

    expect(JevDecision::query()->sole()->labels)->not->toHaveKey('call');
});

it('uses normalized title uniqueness and Unicode case folding before labeling', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    $completed->update(['title' => 'Straße']);
    Task::query()->create(['parent_id' => $group->id, 'position' => 4, 'title' => 'STRASSE', 'brief' => 'Same after normalization.', 'status' => TaskStatus::Completed]);
    Classification::fake([
        ['subtask_'.$completed->id => new BooleanAnswer(0.2), 'subtask_'.Task::query()->where('title', 'STRASSE')->value('id') => new BooleanAnswer(0.2)],
    ])->preventStrayClassifications();
    app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskTurnPullRequest('Summary.', ['Straße'], []), 85, ['Straße']);
    app(BriefCoverageLabeler::class)->label($group, new TaskPullRequestHealth(state: 'merged', pullRequestNumber: 42, mergeBody: "## Changes\n\n- STRASSE\n", mergeSha: 'merge-sha', mergedAt: '2026-10-01T10:00:00Z'));

    expect(JevDecision::query()->sole()->labels)->toBeNull();
});

it('case-folds Unicode titles when matching merge change lines', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    $completed->update(['title' => 'Straße']);
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.2)]])->preventStrayClassifications();
    app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskTurnPullRequest('Summary.', ['Fix STRASSE behavior'], []), 86, ['Fix STRASSE behavior']);
    app(BriefCoverageLabeler::class)->label($group, new TaskPullRequestHealth(state: 'merged', pullRequestNumber: 42, mergeBody: "## Changes\n\n- Fix STRASSE behavior\n", mergeSha: 'merge-sha', mergedAt: '2026-10-01T10:00:00Z'));

    expect(JevDecision::query()->sole()->labels['questions']['subtask_'.$completed->id]['label'])->toBe('false_negative');
});

it('labels only from merged pull-request change evidence', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.2)]])->preventStrayClassifications();
    app(LaravelAiTaskBriefCoverage::class)->missing($group, cancelled_brief_coverage_pull_request(), 87, ['Orders export as CSV.']);
    $decision = JevDecision::query()->sole();
    $health = new TaskPullRequestHealth(state: 'merged', pullRequestNumber: 42, mergeBody: "## Changes\n\n- Orders export as CSV.\n", mergeSha: 'merge-sha', mergedAt: '2026-10-01T10:00:00Z');
    app(BriefCoverageLabeler::class)->label($group, new TaskPullRequestHealth(state: 'open', pullRequestNumber: 42, mergeBody: $health->mergeBody, mergeSha: 'not-merged', mergedAt: $health->mergedAt));
    expect($decision->fresh()->labels)->toBeNull();

    app(BriefCoverageLabeler::class)->label($group, $health);
    expect($decision->fresh()->labels['questions']['subtask_'.$completed->id]['label'])->toBe('false_negative');
});

it('keeps the coverage decision unchanged when bookkeeping failure occurs during recording', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.2)]])->preventStrayClassifications();
    Exceptions::fake();
    DB::statement("CREATE TRIGGER fail_jev_decision_insert BEFORE INSERT ON jev_decisions BEGIN SELECT RAISE(ABORT, 'jev bookkeeping insert failed'); END");

    $missing = app(LaravelAiTaskBriefCoverage::class)->missing($group, cancelled_brief_coverage_pull_request());

    expect($missing)->toBe(['Export'])
        ->and(JevDecision::query()->count())->toBe(0);
    Exceptions::assertReported(QueryException::class);
});

it('marks long Jev evidence incomplete and leaves its question unlabeled', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    $longBrief = str_repeat('Detailed coverage requirement. ', 4000);
    $group->update(['brief' => $longBrief]);
    $completed->update(['brief' => $longBrief]);
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.2)]])->preventStrayClassifications();

    app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskTurnPullRequest('Export feature.', ['Adds Export'], []), 89, ['Adds Export']);
    app(BriefCoverageLabeler::class)->label($group, new TaskPullRequestHealth(
        state: 'merged',
        pullRequestNumber: 42,
        mergeBody: "Summary.\n\n## Changes\n\n- Adds Export\n",
        mergeSha: 'merge-sha',
        mergedAt: '2026-10-01T10:00:00Z',
    ));

    $stored = JevDecision::query()->sole();
    expect($stored->questions['__orbit_truncated__'] ?? false)->toBeTrue()
        ->and($stored->input_state['__orbit_truncated__'] ?? false)->toBeTrue()
        ->and($stored->merge_changes)->toBe(['Adds Export'])
        ->and($stored->merge_changes_redacted)->toBeFalse()
        ->and($stored->labels)->toBeNull();
});

it('caps snapshots under the JSON byte limit when many short fields dominate', function (): void {
    $state = ['subtasks' => array_fill(0, 3000, ['title' => 'Export', 'brief' => 'Add CSV'])];
    app(JevRecorder::class)->record('brief_coverage', [], [], $state, null, null, null, hrtime(true), now()->toIso8601String());

    $stored = DB::table('jev_decisions')->sole();
    $decoded = json_decode($stored->input_state, true, flags: JSON_THROW_ON_ERROR);
    expect(strlen($stored->input_state))->toBeLessThanOrEqual(65536)
        ->and($decoded['__orbit_truncated__'] ?? false)->toBeTrue();
});

it('caps Unicode questions, input state, and merge changes at the persisted JSON byte limit', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    $longLine = 'CSV export '.str_repeat('é', 20000);
    $group->update(['brief' => $longLine]);
    $completed->update(['brief' => $longLine]);
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.97)]])->preventStrayClassifications();

    app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskTurnPullRequest('Summary.', [$longLine], []), 81, ['Export']);
    app(BriefCoverageLabeler::class)->label($group, new TaskPullRequestHealth(
        state: 'merged',
        pullRequestNumber: 42,
        mergeBody: "Summary.\n\n## Changes\n\n- {$longLine}\n",
        mergeSha: 'merge-sha',
        mergedAt: '2026-10-01T10:00:00Z',
    ));

    $stored = DB::table('jev_decisions')->sole();
    expect(strlen($stored->questions))->toBeLessThanOrEqual(65536)
        ->and(strlen($stored->input_state))->toBeLessThanOrEqual(65536)
        ->and(strlen($stored->merge_changes))->toBeLessThanOrEqual(65536)
        ->and($stored->questions)->toContain('truncated')
        ->and(array_key_exists('subtask_'.$completed->id, json_decode($stored->questions, true)))->toBeTrue()
        ->and($stored->input_state)->toContain('truncated')
        ->and(array_keys(json_decode($stored->input_state, true)))->toContain('subtasks')
        ->and($stored->merge_changes)->toContain('truncated');
});

it('completes the group when bookkeeping failure occurs during labeling', function (): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    Classification::fake([['subtask_'.$completed->id => new BooleanAnswer(0.2)]])->preventStrayClassifications();
    app(LaravelAiTaskBriefCoverage::class)->missing($group, cancelled_brief_coverage_pull_request(), 81, ['Orders export as CSV.']);
    $merge = new TaskPullRequestHealth(
        state: 'merged',
        pullRequestNumber: 42,
        mergeBody: "Summary.\n\n## Changes\n\n- Orders export as CSV.\n",
        mergeSha: 'actual-merge-sha',
        mergedAt: '2026-10-01T10:00:00Z',
    );
    $group->update(['status' => 'settling', 'execution_mode' => 'managed', 'pr_url' => 'https://github.com/acme/shop/pull/42']);
    app()->instance(TaskPullRequestWatcher::class, new class($merge) implements TaskPullRequestWatcher
    {
        public function __construct(private TaskPullRequestHealth $merge) {}

        public function status(Task $group): ?string
        {
            return 'merged';
        }

        public function health(Task $group): ?TaskPullRequestHealth
        {
            return $this->merge;
        }
    });
    app(TaskExtensionState::class)->enable();
    mock(ExceptionHandler::class)->shouldReceive('report')->once()->andThrow(new RuntimeException('reporter failed'));
    DB::statement("CREATE TRIGGER fail_jev_decision_update BEFORE UPDATE ON jev_decisions BEGIN SELECT RAISE(ABORT, 'jev bookkeeping label failed'); END");

    app(TaskScheduler::class)->tick();

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Completed);
});

it('still reports an unmatched active subtask', function (TaskStatus $status): void {
    [$group, $cancelled, $failed, $completed] = cancelled_brief_coverage_group();
    $active = Task::query()->create([
        'parent_id' => $group->id,
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
