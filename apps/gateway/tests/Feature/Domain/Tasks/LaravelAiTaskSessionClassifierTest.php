<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskSessionClassificationException;
use App\Domain\Tasks\TaskSessionClassifier;
use App\Domain\Tasks\TaskSessionNextAction;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskThreadObservation;
use App\Domain\Tasks\TaskThreadRole;
use App\Infrastructure\Ai\ChoiceAnswer;
use App\Infrastructure\Ai\Classification;
use App\Infrastructure\Tasks\LaravelAiTaskSessionClassifier;
use Illuminate\Support\Facades\Http;

function classifier_observation(
    string $sessState = 'idle',
    bool $idle = true,
    ?string $pendingApprovalId = null,
    ?string $pendingUserInputId = null,
    ?string $reviewerText = null,
    bool $hasPendingSubtasks = true,
    ?string $prUrl = null,
): TaskSessionObservation {
    return new TaskSessionObservation(
        groupId: 21,
        groupStatus: 'running',
        title: 'Route sessions',
        brief: 'Pick the next mechanical action.',
        hasPendingSubtasks: $hasPendingSubtasks,
        prUrl: $prUrl,
        ciSummary: $prUrl === null ? null : 'passing',
        threads: [
            new TaskThreadObservation(
                threadId: 'implementer-thread',
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
            ),
            new TaskThreadObservation(
                threadId: 'reviewer-thread',
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
    Classification::fake([
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::DrainApproval->value, 0.91),
    ]);

    $decision = app(LaravelAiTaskSessionClassifier::class)->classify(
        classifier_observation(pendingApprovalId: 'approval-1'),
    );

    expect($decision->action)->toBe(TaskSessionNextAction::DrainApproval)
        ->and($decision->confidence)->toBe(0.91)
        ->and($decision->reason)->toBe('Jev selected drain_approval.');
});

it('fails closed when the TypeSafe key is missing', function (): void {
    Http::preventStrayRequests();
    Classification::resetFake();
    config()->set('ai.providers.typesafe.key', null);

    expect(fn () => app(LaravelAiTaskSessionClassifier::class)->classify(classifier_observation()))
        ->toThrow(
            TaskSessionClassificationException::class,
            'TYPESAFE_API_KEY is missing. Task session routing will not invent a next action.',
        );
});

it('selects drain_approval for a pending approval fixture', function (): void {
    Classification::fake([
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::DrainApproval->value, 0.88),
    ]);

    expect(app(LaravelAiTaskSessionClassifier::class)->classify(
        classifier_observation(sessState: 'waiting', idle: false, pendingApprovalId: 'approval-9'),
    )->action)->toBe(TaskSessionNextAction::DrainApproval);
});

it('relays a reviewer summary when the implementer is idle', function (): void {
    Classification::fake([
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::RelayReviewToImplementer->value, 0.84),
    ]);

    expect(app(LaravelAiTaskSessionClassifier::class)->classify(
        classifier_observation(reviewerText: 'Please add tests, then stop.'),
    )->action)->toBe(TaskSessionNextAction::RelayReviewToImplementer);
});

it('marks a verified subtask done when more work remains', function (): void {
    Classification::fake([
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::MarkSubtaskDone->value, 0.9),
    ]);

    expect(app(LaravelAiTaskSessionClassifier::class)->classify(
        classifier_observation(prUrl: 'https://github.com/nckrtl/orbit/pull/21'),
    )->action)->toBe(TaskSessionNextAction::MarkSubtaskDone);
});

it('settles a verified commit with a pull request', function (): void {
    Classification::fake([
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::SettleGroup->value, 0.93),
    ]);

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
    Classification::fake([
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::DrainApproval->value, 0.2),
    ]);

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
