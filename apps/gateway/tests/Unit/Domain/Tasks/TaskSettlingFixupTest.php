<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskPullRequestCheck;
use App\Domain\Tasks\TaskSettlingFixup;

it('orders a conflict before reproducible checks and then other checks', function (): void {
    $checks = [
        new TaskPullRequestCheck('Custom', 'https://example.test/custom'),
        new TaskPullRequestCheck('Gateway', 'https://example.test/gateway'),
        new TaskPullRequestCheck('CLI', null),
    ];

    $orbit = array_map(
        static fn (TaskSettlingFixup $plan): string => $plan->identity,
        TaskSettlingFixup::plans('orbit', true, 'main', $checks),
    );
    $other = array_map(
        static fn (TaskSettlingFixup $plan): string => $plan->identity,
        TaskSettlingFixup::plans('shop', true, 'main', $checks),
    );

    expect($orbit)->toBe(['conflict:main', 'check:Gateway', 'check:CLI', 'check:Custom'])
        ->and($other)->toBe(['conflict:main', 'check:Custom', 'check:Gateway', 'check:CLI']);
});

it('keeps the check url out of the identity and truncates only the title', function (): void {
    $name = str_repeat('n', 200);
    $plan = TaskSettlingFixup::plans('orbit', false, null, [
        new TaskPullRequestCheck($name, 'https://example.test/run'),
    ])[0];

    expect($plan->identity)->toBe('check:'.$name)
        ->and(mb_strlen($plan->title))->toBe(160)
        ->and($plan->title)->toBe(mb_substr('Fix '.$name, 0, 160))
        ->and($plan->brief)->toBe('Check '.$name.' failed: https://example.test/run. Do not rebase and do not force-push.')
        ->and($plan->conflictBase())->toBeNull()
        ->and($plan->deliverables)->toHaveCount(1);
});

it('stores the orbit reproduction command once and skips it for another project', function (): void {
    $orbit = TaskSettlingFixup::plans('orbit', false, null, [
        new TaskPullRequestCheck('Web', 'https://example.test/web'),
        new TaskPullRequestCheck('gateway', 'https://example.test/lower'),
    ]);
    $shop = TaskSettlingFixup::plans('shop', true, null, [
        new TaskPullRequestCheck('Gateway', null),
    ]);

    expect($orbit[0]->deliverables[1]['command'] ?? null)->toBe('copy=$(mktemp) && cp src/api/schema.d.ts "$copy" && bun run types && git diff --exit-code --no-index "$copy" src/api/schema.d.ts && bun run check && bun run test && bun run build')
        ->and($orbit[0]->deliverables[1]['directory'] ?? null)->toBe('apps/web')
        ->and($orbit[0]->deliverables[1]['description'] ?? null)->toBe('Reproduce Web')
        ->and($orbit[1]->identity)->toBe('check:gateway')
        ->and($orbit[1]->deliverables)->toHaveCount(1)
        ->and($shop[0]->conflictBase())->toBe('the base branch')
        ->and($shop[0]->brief)->toBe('Merge origin/the base branch into the task branch and resolve the conflicts. Do not rebase and do not force-push.')
        ->and($shop[1]->brief)->toBe('Check Gateway failed. Do not rebase and do not force-push.')
        ->and($shop[1]->deliverables)->toHaveCount(1);
});
