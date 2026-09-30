<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskPullRequestCheck;
use App\Domain\Tasks\TaskSettlingFixup;

it('orders a conflict before failed checks in GitHub order for any Project check', function (?string $taskCheck): void {
    $checks = [
        new TaskPullRequestCheck('Custom', 'https://example.test/custom'),
        new TaskPullRequestCheck('Gateway', 'https://example.test/gateway'),
        new TaskPullRequestCheck('CLI', null),
    ];

    $identities = array_map(
        static fn (TaskSettlingFixup $plan): string => $plan->identity,
        TaskSettlingFixup::plans($taskCheck, true, 'main', $checks),
    );

    expect($identities)->toBe(['conflict:main', 'check:Custom', 'check:Gateway', 'check:CLI']);
})->with([
    'make check' => ['make check'],
    'no check' => [null],
]);

it('keeps the check url out of the identity and truncates only the title', function (): void {
    $name = str_repeat('n', 200);
    $plan = TaskSettlingFixup::plans('make check', false, null, [
        new TaskPullRequestCheck($name, 'https://example.test/run'),
    ])[0];

    expect($plan->identity)->toBe('check:'.$name)
        ->and(mb_strlen($plan->title))->toBe(160)
        ->and($plan->title)->toBe(mb_substr('Fix '.$name, 0, 160))
        ->and($plan->brief)->toBe('Check '.$name.' failed: https://example.test/run. Do not rebase and do not force-push.')
        ->and($plan->conflictBase())->toBeNull()
        ->and($plan->deliverables)->toHaveCount(1);
});

it('runs the configured check from the workspace root for a conflict and a failed check', function (): void {
    $plans = TaskSettlingFixup::plans('  make check  ', true, null, [
        new TaskPullRequestCheck('Gateway', null),
    ]);
    $deliverable = [[
        'id' => 'project-check',
        'type' => 'command',
        'description' => 'Run the Project task check',
        'command' => 'make check',
        'directory' => '.',
    ]];

    expect($plans[0]->conflictBase())->toBe('the base branch')
        ->and($plans[0]->brief)->toBe('Merge origin/the base branch into the task branch and resolve the conflicts. Do not rebase and do not force-push.')
        ->and($plans[0]->deliverables)->toBe($deliverable)
        ->and($plans[1]->brief)->toBe('Check Gateway failed. Do not rebase and do not force-push.')
        ->and($plans[1]->deliverables)->toBe($deliverable);
});

it('does not add a CI reproduction command for an Orbit job name', function (): void {
    $plans = TaskSettlingFixup::plans('composer check', false, null, [
        new TaskPullRequestCheck('Custom', null),
        new TaskPullRequestCheck('Gateway', null),
        new TaskPullRequestCheck('Rust agent', null),
    ]);

    expect(array_map(static fn (TaskSettlingFixup $plan): string => $plan->identity, $plans))
        ->toBe(['check:Custom', 'check:Gateway', 'check:Rust agent'])
        ->and($plans[1]->deliverables)->toBe([[
            'id' => 'project-check',
            'type' => 'command',
            'description' => 'Run the Project task check',
            'command' => 'composer check',
            'directory' => '.',
        ]]);
});

it('asks for a review when the Project has no check', function (?string $taskCheck): void {
    $plans = TaskSettlingFixup::plans($taskCheck, true, 'main', [
        new TaskPullRequestCheck('Gateway', 'https://example.test/gateway'),
    ]);
    $review = [[
        'id' => 'fixup-review',
        'type' => 'review',
        'description' => 'Confirm the conflict or failed check is resolved from the available evidence.',
    ]];

    expect($plans[0]->deliverables)->toBe($review)
        ->and($plans[1]->deliverables)->toBe($review);
})->with([
    'null' => [null],
    'blank' => ['   '],
]);
