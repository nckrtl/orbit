<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskRubricItem;
use App\Domain\Tasks\TaskRubricReminder;
use App\Domain\Tasks\TaskThreadRole;

it('asks a finished implementer for a summary without claiming a blocker', function (): void {
    $reminder = TaskRubricReminder::compose(TaskThreadRole::Implementer, [
        new TaskRubricItem('check_invoked', false, 'composer check was not found in the recent tool output. Run composer check.'),
        new TaskRubricItem('blocked', false, '', 'yes', 0.6),
    ]);

    expect($reminder)->toBe('Orbit could not confirm the brief is complete. composer check was not found in the recent tool output. Run composer check. If it is, reply with a short summary of what changed and the composer check result. If something outside the brief stops you, say what it is.')
        ->and($reminder)->not->toContain('is blocked')
        ->and($reminder)->not->toContain('assistance_requested')
        ->and(TaskRubricReminder::isReminder($reminder))->toBeTrue();
});

it('writes the reviewer reminder with its own lead and closing', function (): void {
    $reminder = TaskRubricReminder::compose(TaskThreadRole::Reviewer, [
        new TaskRubricItem('outcome_comment', false, 'Post one changes_requested or approved comment for this review attempt.'),
        new TaskRubricItem('blocked', false, '', 'yes', 0.9),
    ]);

    expect($reminder)->toBe('Orbit could not confirm the review is complete. Post one changes_requested or approved comment for this review attempt. If something outside the review stops you, say what it is.')
        ->and(TaskRubricReminder::isReminder("\n".$reminder))->toBeTrue();
});

it('recognizes only messages that start with a reminder lead', function (string $text): void {
    expect(TaskRubricReminder::isReminder($text))->toBeFalse();
})->with([
    'brief' => 'Implement this subtask in the shared workspace, then stop so the reviewer can inspect it.',
    'quoted lead' => 'You said: Orbit could not confirm the brief is complete.',
    'review handoff' => 'please review',
]);
