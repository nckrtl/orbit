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

    expect($reminder)->toBe('Orbit could not confirm the brief is complete. composer check was not found in the recent tool output. Run composer check. No run receipt was found. '.TaskRunInstructions::implementer());
});

it('tells a reviewer how to end its turn with the run script', function (): void {
    $reminder = TaskRubricReminder::compose(TaskThreadRole::Reviewer, [
        new TaskRubricItem('run_receipt', false, 'No run receipt was found.'),
    ]);

    expect($reminder)->toBe('Orbit could not confirm the review is complete. No run receipt was found. '.TaskRunInstructions::reviewer());
});

it('tells the reviewer that the turn is read-only', function (): void {
    expect(TaskRunInstructions::reviewer())
        ->toContain('This review is read-only. Do not create, edit, reset, or delete workspace files, including disposable fixtures.')
        ->toContain('Request changes from the implementer instead.')
        ->not->toContain('You may create');
});

it('tells the implementer to pass the Project task check, or only to finish the brief without one', function (): void {
    expect(TaskRunInstructions::implementer([], 'vp run check'))
        ->toContain('When the brief is complete and vp run check passes, end your turn')
        ->and(TaskRunInstructions::implementer([], null))
        ->toContain('When the brief is complete, end your turn')
        ->not->toContain('composer check');
});
