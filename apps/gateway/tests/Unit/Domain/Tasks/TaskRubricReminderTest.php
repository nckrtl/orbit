<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskRubricItem;
use App\Domain\Tasks\TaskRubricReminder;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\Tasks\TaskTurnMode;

it('tells a finished implementer how to end its turn with the turn command', function (): void {
    $reminder = TaskRubricReminder::compose(TaskThreadRole::Implementer, [
        new TaskRubricItem('check_invoked', false, 'composer check was not found in the recent tool output. Run composer check.'),
        new TaskRubricItem('turn_receipt', false, 'No turn receipt was found.'),
    ]);

    expect($reminder)->toBe('Orbit could not confirm the brief is complete. composer check was not found in the recent tool output. Run composer check. No turn receipt was found. '.TaskTurnInstructions::implementer());
});

it('tells a reviewer how to end its turn with the turn command', function (): void {
    $reminder = TaskRubricReminder::compose(TaskThreadRole::Reviewer, [
        new TaskRubricItem('turn_receipt', false, 'No turn receipt was found.'),
    ]);

    expect($reminder)->toBe('Orbit could not confirm the review is complete. No turn receipt was found. '.TaskTurnInstructions::reviewer());
});

it('does not assume a Project task check when none was supplied', function (): void {
    expect(TaskTurnInstructions::implementer())
        ->not->toContain('composer check')
        ->and(TaskRubricReminder::compose(TaskThreadRole::Implementer, []))
        ->not->toContain('composer check');
});

it('tells the reviewer that the turn is read-only', function (): void {
    expect(TaskTurnInstructions::reviewer())
        ->toContain('This review is read-only. Do not create, edit, reset, or delete workspace files, including disposable fixtures.')
        ->toContain('Request changes from the implementer instead.')
        ->toContain('--outcome=approved')
        ->toContain('--outcome=changes_requested')
        ->not->toContain('This is a consult')
        ->not->toContain('--outcome=answered')
        ->not->toContain('You may create');
});

it('uses consult and relay instructions only for those turns', function (): void {
    $consult = TaskRubricReminder::compose(TaskThreadRole::Reviewer, [
        new TaskRubricItem('turn_receipt', false, 'No turn receipt was found.'),
    ], mode: new TaskTurnMode(consult: true));
    $relay = TaskRubricReminder::compose(TaskThreadRole::Reviewer, [
        new TaskRubricItem('turn_receipt', false, 'No turn receipt was found.'),
    ], mode: new TaskTurnMode(relay: true));

    expect($consult)->toBe('Orbit could not confirm the review is complete. No turn receipt was found. '.TaskTurnInstructions::consult())
        ->and($consult)->toContain('This is a consult, not a review.')
        ->and($consult)->toContain('--outcome=answered')
        ->and($consult)->not->toContain('--outcome=approved')
        ->and($relay)->toBe('Orbit could not confirm the review is complete. No turn receipt was found. '.TaskTurnInstructions::relay())
        ->and($relay)->toContain("This is a relay of the operator's direction, not a review.")
        ->and($relay)->toContain('--outcome=answered')
        ->and($relay)->not->toContain('--outcome=approved');
});

it('tells the implementer to pass the Project task check, or only to finish the brief without one', function (): void {
    expect(TaskTurnInstructions::implementer([], 'vp run check'))
        ->toContain('When the brief is complete and vp run check passes, end your turn')
        ->toContain('Orbit runs vp run check again at handoff with access you do not have, such as sudo. When it fails for you only because you lack that access, hand off anyway.')
        ->and(TaskTurnInstructions::implementer([], null))
        ->toContain('When the brief is complete, end your turn')
        ->not->toContain('composer check')
        ->not->toContain('again at handoff');
});
