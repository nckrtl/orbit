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
