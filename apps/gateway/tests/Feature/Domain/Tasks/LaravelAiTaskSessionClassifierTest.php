<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskJevOutcome;
use App\Domain\Tasks\TaskSessionClassificationException;
use App\Domain\Tasks\TaskSessionClassifier;
use App\Domain\Tasks\TaskSessionNextAction;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskThreadObservation;
use App\Domain\Tasks\TaskThreadRole;
use App\Infrastructure\Tasks\LaravelAiTaskSessionClassifier;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
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
            ),
        ],
    );
}

it('returns the faked Choice as the next action', function (): void {
    Http::preventStrayRequests();
    Classification::fake([[
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::DrainApproval->value, [], 0.91),
    ]]);

    $decision = app(LaravelAiTaskSessionClassifier::class)->classify(
        classifier_observation(pendingApprovalId: 'approval-1'),
    );

    expect($decision->action)->toBe(TaskSessionNextAction::DrainApproval)
        ->and($decision->confidence)->toBe(0.91)
        ->and($decision->reason)->toBe('Jev selected drain_approval.');
});

it('uses the package fake without a TypeSafe key', function (): void {
    Classification::fake();
    config()->set('ai.providers.typesafe.key', null);

    expect(app(LaravelAiTaskSessionClassifier::class)->classify(classifier_observation())->action)
        ->toBe(TaskSessionNextAction::EscalateCoder);
});

it('selects drain_approval for a pending approval fixture', function (): void {
    Classification::fake([[
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::DrainApproval->value, [], 0.88),
    ]]);

    expect(app(LaravelAiTaskSessionClassifier::class)->classify(
        classifier_observation(sessState: 'waiting', idle: false, pendingApprovalId: 'approval-9'),
    )->action)->toBe(TaskSessionNextAction::DrainApproval);
});

it('relays a reviewer summary when the implementer is idle', function (): void {
    Classification::fake([[
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::RelayReviewToImplementer->value, [], 0.84),
    ]]);

    expect(app(LaravelAiTaskSessionClassifier::class)->classify(
        classifier_observation(reviewerText: 'Please add tests, then stop.'),
    )->action)->toBe(TaskSessionNextAction::RelayReviewToImplementer);
});

it('marks a verified subtask done when more work remains', function (): void {
    Classification::fake([[
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::MarkSubtaskDone->value, [], 0.9),
    ]]);

    expect(app(LaravelAiTaskSessionClassifier::class)->classify(
        classifier_observation(prUrl: 'https://github.com/nckrtl/orbit/pull/21'),
    )->action)->toBe(TaskSessionNextAction::MarkSubtaskDone);
});

it('settles a verified commit with a pull request', function (): void {
    Classification::fake([[
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::SettleGroup->value, [], 0.93),
    ]]);

    $decision = app(LaravelAiTaskSessionClassifier::class)->classify(
        classifier_observation(
            hasPendingSubtasks: false,
            prUrl: 'https://github.com/nckrtl/orbit/pull/21',
        ),
    );

    expect($decision->action)->toBe(TaskSessionNextAction::SettleGroup);
});

it('escalates when Choice confidence is below the gate', function (): void {
    config()->set('orbit.tasks.jev_confidence_threshold', 0.75);
    Classification::fake([[
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::DrainApproval->value, [], 0.2),
    ]]);

    $decision = app(LaravelAiTaskSessionClassifier::class)->classify(
        classifier_observation(pendingApprovalId: 'approval-low'),
    );

    expect($decision->action)->toBe(TaskSessionNextAction::EscalateCoder)
        ->and($decision->confidence)->toBe(0.2)
        ->and($decision->reason)->toBe('Choice confidence 0.2 is below 0.75.');
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

describe('provider failures', function (): void {
    it('reports a provider error as a classification failure without the response body', function (): void {
        config()->set('ai.providers.typesafe.key', 'typesafe-test-key');
        Classification::fake(fn () => throw new RequestException(new Response(new Psr7Response(500, [], '{"detail":"provider body"}'))));

        expect(fn () => app(LaravelAiTaskSessionClassifier::class)->classify(classifier_observation()))
            ->toThrow(TaskSessionClassificationException::class, 'TypeSafe Jev request failed (RequestException).');
        try {
            app(LaravelAiTaskSessionClassifier::class)->classify(classifier_observation());
        } catch (TaskSessionClassificationException $exception) {
            expect($exception->getMessage())->not->toContain('provider body');
        }
    });

    it('reports an unreachable provider as a classification failure', function (): void {
        config()->set('ai.providers.typesafe.key', 'typesafe-test-key');
        Classification::fake(fn () => throw new ConnectionException('Connection refused'));

        expect(fn () => app(LaravelAiTaskSessionClassifier::class)->classify(classifier_observation()))
            ->toThrow(TaskSessionClassificationException::class, 'TypeSafe Jev request failed (ConnectionException).');
    });

    it('names the missing key when a request fails without one', function (?string $key): void {
        config()->set('ai.providers.typesafe.key', $key);
        Classification::fake(fn () => throw new RequestException(new Response(new Psr7Response(403, [], '{"detail":"Must supply an API key!"}'))));

        expect(fn () => app(LaravelAiTaskSessionClassifier::class)->classifyOutcome(classifier_observation(), TaskThreadRole::Implementer))
            ->toThrow(TaskSessionClassificationException::class, 'TypeSafe Jev is not configured. Set TYPESAFE_API_KEY.');
    })->with(['missing' => [null], 'empty' => ['']]);
});
