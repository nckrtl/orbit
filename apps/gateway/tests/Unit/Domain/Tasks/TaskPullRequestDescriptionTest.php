<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskPullRequestDescription;
use App\Domain\Tasks\TaskRunPullRequest;

it('renders the summary, changes, breaking changes, and the check line in order', function (): void {
    $description = TaskPullRequestDescription::render(new TaskRunPullRequest(
        'Adds an order export.',
        ['Orders export as CSV.', 'A download route serves the export.'],
        ['The orders:export command is renamed.'],
    ), 2);

    expect($description)->toBe(<<<'MD'
        Adds an order export.

        ## Changes

        - Orders export as CSV.
        - A download route serves the export.

        ## Breaking changes

        - The orders:export command is renamed.

        Checks: each of the 2 subtasks passed `composer check` and was approved by the reviewer.

        MD);
});

it('states that nothing breaks when the reviewer passed none', function (): void {
    expect(TaskPullRequestDescription::render(new TaskRunPullRequest('Summary.', ['Change.'], []), 1))
        ->toContain("## Breaking changes\n\nNone.");
});

it('accepts only a complete pull request description from a receipt', function (mixed $data, bool $valid): void {
    expect(TaskRunPullRequest::fromArray($data) instanceof TaskRunPullRequest)->toBe($valid);
})->with([
    'complete' => [['summary' => 'S', 'changes' => ['C'], 'breaking' => []], true],
    'no changes' => [['summary' => 'S', 'changes' => [], 'breaking' => []], false],
    'empty summary' => [['summary' => ' ', 'changes' => ['C'], 'breaking' => []], false],
    'missing breaking' => [['summary' => 'S', 'changes' => ['C']], false],
    'empty change' => [['summary' => 'S', 'changes' => [''], 'breaking' => []], false],
    'not an array' => [null, false],
]);

it('names the Project task check, or only the review when the Project has none', function (): void {
    $pullRequest = new TaskRunPullRequest('Summary.', ['Change.'], []);

    expect(TaskPullRequestDescription::render($pullRequest, 1, 'vp run check'))
        ->toContain('Checks: each of the 1 subtasks passed `vp run check` and was approved by the reviewer.')
        ->and(TaskPullRequestDescription::render($pullRequest, 1, null))
        ->toContain('Checks: each of the 1 subtasks was approved by the reviewer.');
});
