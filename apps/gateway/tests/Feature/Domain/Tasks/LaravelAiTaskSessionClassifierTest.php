<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskJevOutcome;
use App\Domain\Tasks\TaskSessionClassifier;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskThreadObservation;
use App\Domain\Tasks\TaskThreadRole;
use App\Infrastructure\Tasks\LaravelAiTaskSessionClassifier;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

function classifier_observation(
    string $sessState = 'idle',
    bool $idle = true,
    ?string $pendingApprovalId = null,
    ?string $pendingUserInputId = null,
    ?string $reviewerText = null,
    bool $hasPendingSubtasks = true,
    ?string $prUrl = null,
    array $recentMessages = [],
): TaskSessionObservation {
    return new TaskSessionObservation(
        taskId: 42,
        taskStatus: 'running',
        taskTitle: 'Models',
        taskBrief: 'Implement the models.',
        groupId: 21,
        groupStatus: 'running',
        title: 'Route sessions',
        brief: 'Pick the next mechanical action.',
        hasPendingSubtasks: $hasPendingSubtasks,
        prUrl: $prUrl,
        ciSummary: $prUrl === null ? null : 'passing',
        threads: [
            new TaskThreadObservation(
                threadId: 1,
                role: TaskThreadRole::Implementer,
                sessState: $sessState,
                idle: $idle,
                pendingApprovalId: $pendingApprovalId,
                pendingUserInputId: $pendingUserInputId,
                lastAssistantText: 'I stopped after the models.',
                lastUserText: 'Implement the models.',
                hasNewCommitsSinceThreadStart: $prUrl !== null,
                prUrl: $prUrl,
                ciSummary: $prUrl === null ? null : 'passing',
                recentMessages: $recentMessages,
            ),
            new TaskThreadObservation(
                threadId: 2,
                role: TaskThreadRole::Reviewer,
                sessState: 'idle',
                idle: true,
                pendingApprovalId: null,
                pendingUserInputId: null,
                lastAssistantText: $reviewerText,
                lastUserText: null,
                hasNewCommitsSinceThreadStart: $prUrl !== null,
                prUrl: $prUrl,
                ciSummary: $prUrl === null ? null : 'passing',
                recentMessages: $recentMessages,
            ),
        ],
    );
}

it('classifies the three supported outcomes for a stopped reviewer', function (TaskJevOutcome $outcome): void {
    Http::preventStrayRequests();
    Classification::fake([[
        'outcome' => new ChoiceAnswer($outcome->value, [], 0.91),
    ]]);

    $decision = app(LaravelAiTaskSessionClassifier::class)->classifyOutcome(
        classifier_observation(recentMessages: [[
            'id' => 'tool-1', 'kind' => 'activity', 'label' => 'tool',
            'text' => 'composer check passed (exit code 0)', 'at' => '2026-09-22T10:00:00Z',
        ]]),
        TaskThreadRole::Reviewer,
    );

    expect($decision->outcome)->toBe($outcome)
        ->and($decision->confidence)->toBe(0.91)
        ->and($decision->reason)->toBe('Jev selected '.$outcome->value.'.');
})->with(TaskJevOutcome::cases());

it('uses the package fake without a TypeSafe key', function (): void {
    Classification::fake();
    config()->set('ai.providers.typesafe.key', null);

    expect(app(LaravelAiTaskSessionClassifier::class)->classifyOutcome(classifier_observation(), TaskThreadRole::Implementer)->outcome)
        ->toBe(TaskJevOutcome::AssistanceRequired);
});

it('requires assistance when an implementer returns reviewer findings', function (): void {
    Classification::fake([[
        'outcome' => new ChoiceAnswer(TaskJevOutcome::ChangesRequested->value, [], 0.88),
    ]]);

    expect(app(LaravelAiTaskSessionClassifier::class)->classifyOutcome(
        classifier_observation(), TaskThreadRole::Implementer,
    )->outcome)->toBe(TaskJevOutcome::AssistanceRequired);
});

it('requires assistance for an unknown outcome', function (): void {
    Classification::fake([[
        'outcome' => new ChoiceAnswer('unknown', [], 0.84),
    ]]);

    expect(app(LaravelAiTaskSessionClassifier::class)->classifyOutcome(
        classifier_observation(), TaskThreadRole::Reviewer,
    )->outcome)->toBe(TaskJevOutcome::AssistanceRequired);
});

it('requires assistance when outcome confidence is below the configured threshold', function (TaskJevOutcome $outcome): void {
    config()->set('orbit.tasks.jev_confidence_threshold', 0.9);
    Classification::fake([[
        'outcome' => new ChoiceAnswer($outcome->value, [], 0.85),
    ]]);

    $decision = app(LaravelAiTaskSessionClassifier::class)->classifyOutcome(
        classifier_observation(recentMessages: [[
            'id' => 'tool-1', 'kind' => 'activity', 'label' => 'tool',
            'text' => 'composer check passed (exit code 0)', 'at' => '2026-09-22T10:00:00Z',
        ]]),
        TaskThreadRole::Reviewer,
    );

    expect($decision->outcome)->toBe(TaskJevOutcome::AssistanceRequired)
        ->and($decision->confidence)->toBe(0.85)
        ->and($decision->reason)->toBe('Choice confidence 0.85 is below 0.9.');
})->with(TaskJevOutcome::cases());

it('accepts confidence at the default threshold when the setting is invalid', function (): void {
    config()->set('orbit.tasks.jev_confidence_threshold', 'invalid');
    Classification::fake([[
        'outcome' => new ChoiceAnswer(TaskJevOutcome::ChangesRequested->value, [], 0.75),
    ]]);

    $decision = app(LaravelAiTaskSessionClassifier::class)->classifyOutcome(
        classifier_observation(), TaskThreadRole::Reviewer,
    );

    expect($decision->outcome)->toBe(TaskJevOutcome::ChangesRequested);
});

it('is bound as the task session classifier', function (): void {
    expect(app(TaskSessionClassifier::class))->toBeInstanceOf(LaravelAiTaskSessionClassifier::class);
});

it('offers only the three ADR 0113 Jev outcomes', function (): void {
    expect(array_keys(TaskJevOutcome::choiceCriteria()))
        ->toBe(['completed_successfully', 'changes_requested', 'assistance_required']);
});

it('requires a passing composer check in the last five messages for completion', function (): void {
    Classification::fake([['outcome' => new ChoiceAnswer(TaskJevOutcome::CompletedSuccessfully->value, [], 0.95)]]);

    $decision = app(LaravelAiTaskSessionClassifier::class)->classifyOutcome(
        classifier_observation(recentMessages: [[
            'id' => 'tool-1', 'kind' => 'activity', 'label' => 'tool',
            'text' => 'composer check passed (exit code 0)', 'at' => '2026-09-22T10:00:00Z',
        ]]),
        TaskThreadRole::Implementer,
    );

    expect($decision->outcome)->toBe(TaskJevOutcome::CompletedSuccessfully);
});

it('does not treat an assistant claim as composer check evidence', function (): void {
    Classification::fake([['outcome' => new ChoiceAnswer(TaskJevOutcome::CompletedSuccessfully->value, [], 0.95)]]);

    $decision = app(LaravelAiTaskSessionClassifier::class)->classifyOutcome(
        classifier_observation(recentMessages: [[
            'id' => 'assistant-1', 'kind' => 'message', 'label' => 'assistant',
            'text' => 'I ran composer check and everything passed.', 'at' => '2026-09-22T10:00:00Z',
        ]]),
        TaskThreadRole::Implementer,
    );

    expect($decision->outcome)->toBe(TaskJevOutcome::AssistanceRequired);
});
