<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskDeliverableEvidence;
use App\Domain\Tasks\TaskDeliverableVerifier;
use App\Domain\Tasks\TaskThreadRole;

it('matches file deliverable globs where * stays in one directory and ** crosses directories', function (): void {
    expect(TaskDeliverableVerifier::matches('docs/**/*.md', 'docs/reference/tasks.md'))->toBeTrue()
        ->and(TaskDeliverableVerifier::matches('apps/*/README.md', 'apps/gateway/README.md'))->toBeTrue()
        ->and(TaskDeliverableVerifier::matches('apps/*/README.md', 'apps/gateway/docs/README.md'))->toBeFalse()
        ->and(TaskDeliverableVerifier::matches('docs/?.md', 'docs/a.md'))->toBeTrue()
        ->and(TaskDeliverableVerifier::matches('docs/?.md', 'docs/ab.md'))->toBeFalse();
});

it('matches file deliverable brace-alternation globs', function (string $pattern, string $path, bool $matches): void {
    expect(TaskDeliverableVerifier::matches($pattern, $path))->toBe($matches);
})->with([
    'a Data path under directory alternatives' => ['app/{Data,Enums}/PantrySync/**/*.php', 'app/Data/PantrySync/V1/X.php', true],
    'an Enums path under directory alternatives' => ['app/{Data,Enums}/PantrySync/**/*.php', 'app/Enums/PantrySync/V1/Y.php', true],
    'a sibling directory outside the alternatives' => ['app/{Data,Enums}/PantrySync/**/*.php', 'app/Other/PantrySync/X.php', false],
    'a database path under root alternatives' => ['{app,database}/**/*Pantry*.php', 'database/factories/PantryItemFactory.php', true],
    'an app path whose file name has no Pantry' => ['{app,database}/**/*Pantry*.php', 'app/Data/PantrySync/V1/X.php', false],
    'a brace without a comma stays literal' => ['app/{Data}/X.php', 'app/Data/X.php', false],
    'an unclosed brace stays literal' => ['app/{Data,Enums/X.php', 'app/Data/X.php', false],
]);

it('verifies a file deliverable whose path uses brace alternation', function (): void {
    $deliverable = TaskDeliverable::fromArray([
        'id' => 'pantry-sync', 'type' => 'file', 'description' => 'Pantry sync types',
        'path' => 'app/{Data,Enums}/PantrySync/**/*.php', 'change' => 'any',
    ]);
    $match = TaskDeliverableEvidence::fromArray([
        'diff' => [['status' => 'A', 'path' => 'app/Data/PantrySync/V1/X.php']], 'commands' => [],
    ]);
    $miss = TaskDeliverableEvidence::fromArray([
        'diff' => [['status' => 'A', 'path' => 'app/Other/PantrySync/X.php']], 'commands' => [],
    ]);

    expect(TaskDeliverableVerifier::failures([$deliverable], $match))->toBe([])
        ->and(TaskDeliverableVerifier::failures([$deliverable], $miss))->toBe([
            "pantry-sync (file): no path in the subtask's diff matches app/{Data,Enums}/PantrySync/**/*.php.",
        ]);
});

it('renders a generic command deliverable and its base-run requirement in prompts', function (): void {
    $deliverable = TaskDeliverable::fromArray([
        'id' => 'layout-repro',
        'type' => 'command',
        'description' => 'The layout regression fails on the base code',
        'command' => "vendor/bin/pest tests/Feature/HomeScreenTest.php --filter='home screen layout'",
        'directory' => 'apps/gateway',
        'fails_on_base' => true,
        'paths' => ['apps/gateway/tests/Feature/HomeScreenTest.php'],
    ]);

    expect($deliverable->line())->toContain('command:')
        ->toContain('must fail on the start commit and pass on the working tree')
        ->toContain('paths apps/gateway/tests/Feature/HomeScreenTest.php')
        ->and($deliverable->toArray())->toBe([
            'id' => 'layout-repro',
            'type' => 'command',
            'description' => 'The layout regression fails on the base code',
            'command' => "vendor/bin/pest tests/Feature/HomeScreenTest.php --filter='home screen layout'",
            'directory' => 'apps/gateway',
            'fails_on_base' => true,
            'paths' => ['apps/gateway/tests/Feature/HomeScreenTest.php'],
        ]);
});

it('requires a passing working-tree command and a failing base command', function (): void {
    $deliverable = TaskDeliverable::fromArray([
        'id' => 'layout-repro', 'type' => 'command', 'description' => 'Reproduce the regression',
        'command' => 'check-layout', 'directory' => 'apps/gateway', 'fails_on_base' => true,
        'paths' => ['apps/gateway/tests/Feature/HomeScreenTest.php'],
    ]);
    $pass = TaskDeliverableEvidence::fromArray([
        'diff' => [],
        'commands' => ['layout-repro' => ['base_started' => true, 'base_exit_code' => 1, 'base_output' => 'base failure', 'exit_code' => 0, 'output' => '']],
    ]);
    $basePass = TaskDeliverableEvidence::fromArray([
        'diff' => [],
        'commands' => ['layout-repro' => ['base_started' => true, 'base_exit_code' => 0, 'base_output' => '', 'exit_code' => 0, 'output' => '']],
    ]);
    $workingTreeFails = TaskDeliverableEvidence::fromArray([
        'diff' => [],
        'commands' => ['layout-repro' => ['base_started' => true, 'base_exit_code' => 1, 'base_output' => '', 'exit_code' => 2, 'output' => 'still broken']],
    ]);
    $baseDidNotStart = TaskDeliverableEvidence::fromArray([
        'diff' => [],
        'commands' => ['layout-repro' => ['base_started' => false, 'base_exit_code' => 127, 'base_output' => 'Could not extract base', 'exit_code' => 0, 'output' => '']],
    ]);
    $baseCommandMissing = TaskDeliverableEvidence::fromArray([
        'diff' => [],
        'commands' => ['layout-repro' => ['base_started' => true, 'base_exit_code' => 127, 'base_output' => 'command not found', 'exit_code' => 0, 'output' => '']],
    ]);
    $baseCommandNotExecutable = TaskDeliverableEvidence::fromArray([
        'diff' => [],
        'commands' => ['layout-repro' => ['base_started' => true, 'base_exit_code' => 126, 'base_output' => 'permission denied', 'exit_code' => 0, 'output' => '']],
    ]);

    expect(TaskDeliverableVerifier::failures([$deliverable], $pass))->toBe([])
        ->and(TaskDeliverableVerifier::failures([$deliverable], $basePass))->toBe([
            'layout-repro (command): `check-layout` in apps/gateway also exited 0 on the start commit, so it does not reproduce the failure.',
        ])
        ->and(implode(' ', TaskDeliverableVerifier::failures([$deliverable], $workingTreeFails)))->toContain('`check-layout` in apps/gateway exited with 2.')
        ->and(TaskDeliverableVerifier::failures([$deliverable], $baseDidNotStart))->toBe([
            'layout-repro (command): Orbit could not run `check-layout` in apps/gateway on the start commit.',
        ])
        ->and(TaskDeliverableVerifier::failures([$deliverable], $baseCommandMissing))->toBe([
            'layout-repro (command): Orbit could not run `check-layout` in apps/gateway on the start commit (exit 127).',
        ])
        ->and(TaskDeliverableVerifier::failures([$deliverable], $baseCommandNotExecutable))->toBe([
            'layout-repro (command): Orbit could not run `check-layout` in apps/gateway on the start commit (exit 126).',
        ]);
});

it('accepts a timed out base command as nonzero evidence and renders both exit codes', function (): void {
    $deliverable = TaskDeliverable::fromArray([
        'id' => 'layout-repro', 'type' => 'command', 'description' => 'Reproduce the regression',
        'command' => 'check-layout', 'fails_on_base' => true, 'paths' => ['tests/repro.sh'],
    ]);
    $evidence = TaskDeliverableEvidence::fromArray([
        'diff' => [],
        'commands' => ['layout-repro' => [
            'base_started' => true, 'base_exit_code' => 124, 'base_output' => '', 'base_timed_out' => true, 'base_timeout_seconds' => 600,
            'exit_code' => 0, 'output' => '',
        ]],
    ]);

    expect(TaskDeliverableVerifier::failures([$deliverable], $evidence))->toBe([])
        ->and($evidence?->baseRunReview([$deliverable]))->toContain('layout-repro: base command exited 124 (timed out after 600 seconds); working-tree command exited 0');
});

it('continues to verify file deliverables against the subtask diff', function (): void {
    $file = TaskDeliverable::fromArray([
        'id' => 'reference', 'type' => 'file', 'description' => 'Update docs', 'path' => 'docs/**/*.md', 'change' => 'modified',
    ]);
    $evidence = TaskDeliverableEvidence::fromArray(['diff' => [['status' => 'M', 'path' => 'docs/reference/tasks.md']], 'commands' => []]);

    expect(TaskDeliverableVerifier::failures([$file], $evidence))->toBe([]);
});

it('requires implementers to confirm all deliverables and reviewers to confirm review deliverables', function (): void {
    $deliverables = [
        TaskDeliverable::fromArray(['id' => 'lint', 'type' => 'command', 'description' => 'Lint', 'command' => 'lint']),
        TaskDeliverable::fromArray(['id' => 'copy', 'type' => 'review', 'description' => 'Review copy']),
    ];

    expect(TaskDeliverableVerifier::unconfirmed($deliverables, [], TaskThreadRole::Implementer))->toBe(['lint', 'copy'])
        ->and(TaskDeliverableVerifier::unconfirmed($deliverables, [], TaskThreadRole::Reviewer))->toBe(['copy']);
});
