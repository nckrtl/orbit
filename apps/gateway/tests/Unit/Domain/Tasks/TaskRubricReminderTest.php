<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskRubricItem;
use App\Domain\Tasks\TaskRubricReminder;
use App\Domain\Tasks\TaskRunInstructions;
use App\Domain\Tasks\TaskThreadRole;

it('tells a finished implementer how to end its turn with the run script', function (): void {
    $reminder = TaskRubricReminder::compose(TaskThreadRole::Implementer, [
        new TaskRubricItem('check_invoked', false, 'composer check was not found in the recent tool output. Run composer check.'),
        new TaskRubricItem('run_receipt', false, 'No run receipt was found.'),
    ]);

    expect($reminder)->toBe('Orbit could not confirm the brief is complete. composer check was not found in the recent tool output. Run composer check. No run receipt was found. '.TaskRunInstructions::implementer())
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
    'brief' => 'Implement this subtask in the shared workspace. '.TaskRunInstructions::implementer(),
    'quoted lead' => 'You said: Orbit could not confirm the brief is complete.',
    'review handoff' => 'please review',
]);
