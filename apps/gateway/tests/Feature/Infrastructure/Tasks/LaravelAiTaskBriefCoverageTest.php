<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskSessionClassificationException;
use App\Domain\Tasks\TaskTurnPullRequest;
use App\Infrastructure\Tasks\Jev;
use App\Infrastructure\Tasks\LaravelAiTaskBriefCoverage;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

use function Pest\Laravel\mock;

/** @return array{Task, Task, Task} */
function coverage_group(): array
{
    $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Export orders', 'brief' => 'Export orders as CSV and add a download route.', 'status' => 'reviewing']);
    $models = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Export', 'brief' => 'Write the CSV export.', 'status' => 'completed']);
    $routes = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Route', 'brief' => 'Add the download route.', 'status' => 'reviewing']);

    return [$group, $models, $routes];
}

function coverage_pull_request(): TaskTurnPullRequest
{
    return new TaskTurnPullRequest('Adds an order export.', ['Orders export as CSV.'], []);
}

it('names each subtask that no listed change delivers', function (): void {
    [$group, $export, $route] = coverage_group();
    Classification::fake([[
        'subtask_'.$export->id => new BooleanAnswer(0.97),
        'subtask_'.$route->id => new BooleanAnswer(0.2),
    ]])->preventStrayClassifications();

    expect(app(LaravelAiTaskBriefCoverage::class)->missing($group, coverage_pull_request()))->toBe(['Route']);
    Classification::assertClassified(static fn (ClassificationPrompt $prompt): bool => is_array($prompt->state)
        && $prompt->state['group_brief'] === 'Export orders as CSV and add a download route.'
        && $prompt->state['pull_request'] === ['summary' => 'Adds an order export.', 'changes' => ['Orders export as CSV.'], 'breaking' => []]
        && array_column($prompt->state['subtasks'], 'title') === ['Export', 'Route']);
});

it('counts a subtask as covered from a probability of one half', function (): void {
    [$group, $export, $route] = coverage_group();
    Classification::fake([[
        'subtask_'.$export->id => new BooleanAnswer(0.69),
        'subtask_'.$route->id => new BooleanAnswer(0.4),
    ]])->preventStrayClassifications();

    expect(app(LaravelAiTaskBriefCoverage::class)->missing($group, coverage_pull_request()))->toBe(['Route']);
});

/** @return array{Task, Task, Task, Task} the 1261 shape: two completed subtasks and the reviewing publish subtask */
function coverage_publication_group(): array
{
    $project = Project::query()->create(['name' => 'Website', 'slug' => 'website', 'repository_url' => 'git@github.com:acme/website.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Prepare the website release', 'brief' => 'Ship the preparatory work as one pull request.', 'status' => 'reviewing']);
    $audit = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Audit the release checklist', 'brief' => 'List the open release items.', 'status' => 'completed']);
    $copy = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Update the landing copy', 'brief' => 'Rewrite the landing page copy.', 'status' => 'completed']);
    $publish = Task::query()->create(['parent_id' => $group->id, 'position' => 3, 'title' => 'Publish preparatory PR (not CLEAN) with report', 'brief' => 'Open the preparatory pull request and attach the readiness report.', 'status' => 'reviewing']);

    return [$group, $audit, $copy, $publish];
}

/** @return list<string> the four changes of approval 2851 */
function coverage_publication_changes(): array
{
    return [
        'Audit the release checklist: every open release item is listed in docs/release.md.',
        'Update the landing copy: the landing page uses the new product copy.',
        'Publish preparatory PR (not CLEAN) with report: the pull request carries the readiness report and says it is not CLEAN.',
        'The release notes link the readiness report.',
    ];
}

it('covers a subtask whose exact title starts a change without asking Jev', function (): void {
    [$group, $export, $route] = coverage_group();
    Classification::fake([[
        'subtask_'.$export->id => new BooleanAnswer(0.1),
        'subtask_'.$route->id => new BooleanAnswer(0.1),
    ]])->preventStrayClassifications();

    $missing = app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskTurnPullRequest('Adds an order export.', ['  Export: orders export as CSV.', 'Route - the download route serves the file.'], []));

    expect($missing)->toBe([]);
    Classification::assertNothingClassified();
    expect(DB::table('jev_decisions')->count())->toBe(0);
});

it('covers the 2851 change list even when Jev answers false for the publish subtask', function (): void {
    [$group, $audit, $copy, $publish] = coverage_publication_group();
    Classification::fake([[
        'subtask_'.$audit->id => new BooleanAnswer(0.9),
        'subtask_'.$copy->id => new BooleanAnswer(0.9),
        'subtask_'.$publish->id => new BooleanAnswer(0.45),
    ]])->preventStrayClassifications();

    $missing = app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskTurnPullRequest('Prepares the release.', coverage_publication_changes(), []), 2851, coverage_publication_changes());

    expect($missing)->toBe([]);
    Classification::assertNothingClassified();
});

it('still reports the publish subtask missing when its title-prefixed change is omitted', function (): void {
    [$group, $audit, $copy, $publish] = coverage_publication_group();
    $changes = array_values(array_filter(coverage_publication_changes(), static fn (string $change): bool => ! str_starts_with($change, 'Publish preparatory PR')));
    Classification::fake([[
        'subtask_'.$publish->id => new BooleanAnswer(0.45),
    ]])->preventStrayClassifications();

    $missing = app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskTurnPullRequest('Prepares the release.', $changes, []), 2851, $changes);

    expect($missing)->toBe(['Publish preparatory PR (not CLEAN) with report']);
    Classification::assertClassified(static fn (ClassificationPrompt $prompt): bool => is_array($prompt->state)
        && array_column($prompt->state['subtasks'], 'title') === ['Publish preparatory PR (not CLEAN) with report']);
    $record = DB::table('jev_decisions')->sole();
    expect(array_keys(json_decode($record->questions, true)))->toBe(['subtask_'.$publish->id])
        ->and(json_decode($record->task_ids, true))->toBe([$publish->id]);
});

it('leaves a change that only continues the title word, or differs in punctuation, to Jev', function (string $change): void {
    [$group, , , $publish] = coverage_publication_group();
    Classification::fake([[
        'subtask_'.$publish->id => new BooleanAnswer(0.45),
    ]])->preventStrayClassifications();
    $changes = [
        'Audit the release checklist.',
        'Update the landing copy.',
        $change,
    ];

    expect(app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskTurnPullRequest('Prepares the release.', $changes, [])))
        ->toBe(['Publish preparatory PR (not CLEAN) with report']);
})->with([
    'without parentheses' => 'Publish preparatory PR not CLEAN with report: done.',
    'lowercase' => 'publish preparatory PR (not CLEAN) with report: done.',
    'longer last word' => 'Publish preparatory PR (not CLEAN) with reports attached.',
    'title inside the change' => 'Done: Publish preparatory PR (not CLEAN) with report.',
]);

it('records the Jev call', function (): void {
    [$group, $export, $route] = coverage_group();
    Classification::fake([[
        'subtask_'.$export->id => new BooleanAnswer(0.97),
        'subtask_'.$route->id => new BooleanAnswer(0.2),
    ]])->preventStrayClassifications();

    app(LaravelAiTaskBriefCoverage::class)->missing($group, coverage_pull_request());

    $record = DB::table('jev_decisions')->sole();
    expect($record->purpose)->toBe('brief_coverage')
        ->and($record->task_group_id)->toBe($group->id)
        ->and(json_decode($record->questions, true))->toHaveKeys(['subtask_'.$export->id, 'subtask_'.$route->id])
        ->and(json_decode($record->input_state, true)['group_title'])->toBe('Export orders')
        ->and(json_decode($record->answers, true)['subtask_'.$export->id]['value'])->toBeTrue()
        ->and(json_decode($record->answers, true)['subtask_'.$export->id]['probabilities']['true'])->toBe(0.97)
        ->and(abs(json_decode($record->answers, true)['subtask_'.$export->id]['probabilities']['false'] - 0.03))->toBeLessThan(0.000000001)
        ->and(json_decode($record->answers, true)['subtask_'.$export->id]['provider_confidence'])->toBeNull()
        ->and(json_decode($record->answers, true)['subtask_'.$export->id]['selected_answer_probability'])->toBe(0.97)
        ->and($record->latency_ms)->toBeGreaterThanOrEqual(0);
});

it('redacts secrets from persisted state and questions', function (): void {
    [$group, $export, $route] = coverage_group();
    $group->update(['brief' => 'TYPESAFE_API_KEY=example-prefixed-secret DB_PASSWORD=example-db-secret password="first second" {"api_key":"example-json-secret"}']);
    $export->update(['brief' => 'Authorization: Basic ZXhhbXBsZTpwYXNzd29yZA==']);
    $route->update(['brief' => 'Use API_KEY=question-secret.']);
    Classification::fake([[
        'subtask_'.$export->id => new BooleanAnswer(0.97),
        'subtask_'.$route->id => new BooleanAnswer(0.2),
    ]])->preventStrayClassifications();

    app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskTurnPullRequest(
        'Authorization: Bearer summary-secret',
        ['API_KEY=change-secret'],
        [],
    ));

    $record = DB::table('jev_decisions')->sole();
    $serialized = json_encode((array) $record, JSON_THROW_ON_ERROR);
    foreach ([
        'example-prefixed-secret', 'example-db-secret', 'first second', 'first', 'second',
        'ZXhhbXBsZTpwYXNzd29yZA==', 'example-json-secret', 'question-secret',
        'summary-secret', 'change-secret',
    ] as $secret) {
        expect($serialized)->not->toContain($secret);
    }
});

it('records the Jev call failure without provider body or input secrets', function (): void {
    [$group, $export, $route] = coverage_group();
    $group->update(['brief' => 'TYPESAFE_API_KEY=failure-prefixed-secret DB_PASSWORD=failure-db-secret password="failure first second" {"api_key":"failure-json-secret"}']);
    $export->update(['brief' => 'Authorization: Basic ZmFpbHVyZTpwYXNzd29yZA==']);
    $route->update(['brief' => 'Use API_KEY=failure-question-secret.']);
    config()->set('ai.providers.typesafe.key', 'typesafe-test-key');
    Classification::fake(fn () => throw new ConnectionException('SECRET PROVIDER BODY'))->preventStrayClassifications();

    expect(fn () => app(LaravelAiTaskBriefCoverage::class)->missing($group, new TaskTurnPullRequest(
        'Authorization: Bearer failure-summary-secret',
        ['API_KEY=failure-change-secret'],
        [],
    )))->toThrow(TaskSessionClassificationException::class);

    $record = DB::table('jev_decisions')->sole();
    $serialized = json_encode((array) $record, JSON_THROW_ON_ERROR);
    expect($record->error_code)->toBe('connection_exception');
    foreach ([
        'SECRET PROVIDER BODY', 'failure-prefixed-secret', 'failure-db-secret',
        'failure first second', 'failure', 'first', 'second', 'ZmFpbHVyZTpwYXNzd29yZA==',
        'failure-json-secret', 'failure-question-secret', 'failure-summary-secret', 'failure-change-secret',
    ] as $secret) {
        expect($serialized)->not->toContain($secret);
    }
});

it('continues a successful classification when bookkeeping JSON encoding fails', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(NAN)]])->preventStrayClassifications();
    Exceptions::fake();
    $question = new Boolean('Choose one.', ['true' => null, 'false' => null]);
    $state = ['input' => 'value'];
    $classification = Classification::of($state)->question('decision', $question);

    $response = app(Jev::class)->classify($classification, 'json_failure', [], ['decision' => $question], $state);

    expect(is_nan($response->answers['decision']->probability))->toBeTrue();
    Exceptions::assertReported(JsonException::class);
});

it('keeps the classification exception when recording a failed classification also fails', function (): void {
    Classification::fake(fn (): never => throw new ConnectionException('provider failed'))->preventStrayClassifications();
    Exceptions::fake();
    DB::statement("CREATE TRIGGER fail_jev_decision_insert_before_failed_call BEFORE INSERT ON jev_decisions BEGIN SELECT RAISE(ABORT, 'jev bookkeeping insert failed'); END");
    config()->set('ai.providers.typesafe.key', 'typesafe-test-key');
    $group = coverage_group()[0];

    expect(fn () => app(LaravelAiTaskBriefCoverage::class)->missing($group, coverage_pull_request()))
        ->toThrow(TaskSessionClassificationException::class, 'TypeSafe Jev request failed (ConnectionException).');
    Exceptions::assertReported(QueryException::class);
});

it('preserves a failed classification when the bookkeeping limiter fails', function (): void {
    config()->set('ai.providers.typesafe.key', 'typesafe-test-key');
    Classification::fake(fn (): never => throw new ConnectionException('provider failed'))->preventStrayClassifications();
    RateLimiter::shouldReceive('attempt')->once()->andThrow(new RuntimeException('limiter failed'));
    DB::statement("CREATE TRIGGER fail_jev_decision_insert_before_failed_limiter BEFORE INSERT ON jev_decisions BEGIN SELECT RAISE(ABORT, 'jev bookkeeping insert failed'); END");
    $group = coverage_group()[0];

    expect(fn () => app(LaravelAiTaskBriefCoverage::class)->missing($group, coverage_pull_request()))
        ->toThrow(TaskSessionClassificationException::class, 'TypeSafe Jev request failed (ConnectionException).');
});

it('continues a successful classification when the bookkeeping limiter fails', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.8)]])->preventStrayClassifications();
    RateLimiter::shouldReceive('attempt')->once()->andThrow(new RuntimeException('limiter failed'));
    DB::statement("CREATE TRIGGER fail_jev_decision_insert_before_limiter_failure BEFORE INSERT ON jev_decisions BEGIN SELECT RAISE(ABORT, 'jev bookkeeping insert failed'); END");
    $question = new Boolean('Choose one.', ['true' => null, 'false' => null]);
    $state = ['input' => 'value'];
    $classification = Classification::of($state)->question('decision', $question);

    $response = app(Jev::class)->classify($classification, 'limiter_failure', [], ['decision' => $question], $state);

    expect($response->answers)->toHaveKey('decision');
});

it('continues a successful classification when the bookkeeping reporter fails', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.8)]])->preventStrayClassifications();
    mock(ExceptionHandler::class)->shouldReceive('report')->once()->andThrow(new RuntimeException('reporter failed'));
    RateLimiter::shouldReceive('attempt')->once()->andReturnUsing(static fn (mixed $key, mixed $maxAttempts, mixed $callback, mixed $decaySeconds): mixed => $callback());
    DB::statement("CREATE TRIGGER fail_jev_decision_insert_before_report_failure BEFORE INSERT ON jev_decisions BEGIN SELECT RAISE(ABORT, 'jev bookkeeping insert failed'); END");
    $question = new Boolean('Choose one.', ['true' => null, 'false' => null]);
    $state = ['input' => 'value'];
    $classification = Classification::of($state)->question('decision', $question);

    $response = app(Jev::class)->classify($classification, 'reporter_failure', [], ['decision' => $question], $state);

    expect($response->answers)->toHaveKey('decision');
});

it('leaves selected Choice probability null when the selected option has no distribution entry', function (): void {
    Classification::fake([['decision' => new ChoiceAnswer('selected', ['other' => 0.7], 0.8)]])->preventStrayClassifications();
    $question = new Choice('Choose one.', ['selected' => null, 'other' => null]);
    $state = ['input' => 'value'];
    $classification = Classification::of($state)->question('decision', $question);

    app(Jev::class)->classify($classification, 'choice_test', [], ['decision' => $question], $state);

    $answer = json_decode(DB::table('jev_decisions')->sole()->answers, true)['decision'];
    expect($answer)->toMatchArray([
        'value' => 'selected',
        'probabilities' => ['other' => 0.7],
        'provider_confidence' => 0.8,
        'selected_answer_probability' => null,
    ]);
});

it('preserves an explicit zero selected Choice probability', function (): void {
    Classification::fake([['decision' => new ChoiceAnswer('selected', ['selected' => 0.0, 'other' => 1.0], 0.8)]])->preventStrayClassifications();
    $question = new Choice('Choose one.', ['selected' => null, 'other' => null]);
    $state = ['input' => 'value'];
    $classification = Classification::of($state)->question('decision', $question);

    app(Jev::class)->classify($classification, 'choice_test', [], ['decision' => $question], $state);

    $answer = json_decode(DB::table('jev_decisions')->sole()->answers, true)['decision'];
    expect($answer['probabilities'])->toBe(['selected' => 0, 'other' => 1])
        ->and($answer['provider_confidence'])->toBe(0.8)
        ->and($answer['selected_answer_probability'])->toBe(0);
});

it('reports a failed or incomplete Jev answer as a classification failure', function (): void {
    [$group] = coverage_group();
    config()->set('ai.providers.typesafe.key', 'typesafe-test-key');
    Classification::fake(fn () => throw new ConnectionException('Connection refused'))->preventStrayClassifications();

    expect(fn () => app(LaravelAiTaskBriefCoverage::class)->missing($group, coverage_pull_request()))
        ->toThrow(TaskSessionClassificationException::class, 'TypeSafe Jev request failed (ConnectionException).');
});
