<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskDeliverableEvidence;
use App\Domain\Tasks\TaskDeliverableVerifier;
use App\Domain\Tasks\TaskThreadRole;

it('matches a path glob where * stays in one directory, ** crosses directories, and ? is one character', function (string $pattern, string $path, bool $matches): void {
    expect(TaskDeliverableVerifier::matches($pattern, $path))->toBe($matches);
})->with([
    'an exact path' => ['docs/reference/tasks.md', 'docs/reference/tasks.md', true],
    'another path' => ['docs/reference/tasks.md', 'docs/reference/apps.md', false],
    'a star in one directory' => ['docs/reference/*.md', 'docs/reference/tasks.md', true],
    'a star across directories' => ['docs/*.md', 'docs/reference/tasks.md', false],
    'a double star across directories' => ['docs/**/*.md', 'docs/reference/cli/tasks.md', true],
    'a double star with no directory' => ['docs/**/*.md', 'docs/tasks.md', true],
    'a trailing double star' => ['apps/gateway/**', 'apps/gateway/app/Models/Task.php', true],
    'a question mark' => ['docs/decisions/013?-*.md', 'docs/decisions/0133-verify.md', true],
    'a question mark is not a slash' => ['docs?tasks.md', 'docs/tasks.md', false],
    'regex characters stay literal' => ['docs/(draft)+.md', 'docs/(draft)+.md', true],
]);

it('names the deliverables a receipt must confirm and does not, by role', function (): void {
    $deliverables = TaskDeliverable::listFrom([
        ['id' => 'docs', 'type' => 'file', 'description' => 'Docs', 'path' => 'docs/a.md', 'change' => 'any'],
        ['id' => 'copy', 'type' => 'review', 'description' => 'Copy'],
    ]);

    expect(TaskDeliverableVerifier::unconfirmed($deliverables, ['copy' => 'Checked'], TaskThreadRole::Implementer))->toBe(['docs'])
        ->and(TaskDeliverableVerifier::unconfirmed($deliverables, ['docs' => 'Written', 'copy' => ' '], TaskThreadRole::Reviewer))->toBe(['copy'])
        ->and(TaskDeliverableVerifier::unconfirmed($deliverables, ['copy' => 'Checked'], TaskThreadRole::Reviewer))->toBe([]);
});

it('refuses a glob in a test deliverable file and accepts one exact php path', function (string $file, bool $exact): void {
    expect(TaskDeliverable::isExactTestFile($file))->toBe($exact);
})->with([
    'a star' => ['tests/Feature/**/*.php', false],
    'a question mark' => ['tests/Export?.php', false],
    'an opening bracket' => ['tests/Export[0].php', false],
    'an opening brace' => ['tests/{Export}Test.php', false],
    'a parent directory' => ['tests/../ExportTest.php', false],
    'a suffix other than php' => ['tests/ExportTest.md', false],
    'an absolute path' => ['/tmp/ExportTest.php', false],
    'an uppercase suffix' => ['tests/ExportTest.PHP', false],
    'two dots in the name' => ['tests/foo..php', false],
    'an exact path' => ['tests/Feature/ExportTest.php', true],
    'a leading dot slash' => ['./tests/Feature/ExportTest.php', true],
    'a closing bracket' => ['tests/Export].php', true],
    'a closing brace' => ['tests/Export}.php', true],
]);

it('tells the implementer that a fails_on_base test must fail on the start commit', function (): void {
    $deliverable = TaskDeliverable::fromArray([
        'id' => 'layout-repro',
        'type' => 'test',
        'description' => 'The layout fails before the fix',
        'project' => 'apps/gateway',
        'file' => 'tests/Feature/HomeScreenTest.php',
        'name' => 'home screen layout',
        'fails_on_base' => true,
    ]);

    expect($deliverable->line())->toBe('- layout-repro (test: Pest test "home screen layout" in apps/gateway/tests/Feature/HomeScreenTest.php; at least one test whose name contains "home screen layout" must fail on the start commit, and every such test must pass on the working tree): The layout fails before the fix')
        ->and($deliverable->toArray()['fails_on_base'])->toBeTrue()
        ->and(TaskDeliverable::fromArray(['id' => 'export-test', 'type' => 'test', 'description' => 'Test', 'name' => 'exports'])->toArray()['fails_on_base'])->toBeFalse();
});

function fails_on_base_deliverable(): TaskDeliverable
{
    return TaskDeliverable::fromArray([
        'id' => 'layout-repro',
        'type' => 'test',
        'description' => 'The layout fails before the fix',
        'project' => 'apps/gateway',
        'file' => 'tests/Feature/HomeScreenTest.php',
        'name' => 'home screen layout',
        'fails_on_base' => true,
    ]);
}

/**
 * @param  array<string, mixed>  $run
 * @param  list<array{status: string, path: string}>|null  $diff
 */
function fails_on_base_evidence(array $run, ?array $diff = null): TaskDeliverableEvidence
{
    return TaskDeliverableEvidence::fromArray([
        'diff' => $diff ?? [['status' => 'M', 'path' => 'apps/gateway/tests/Feature/HomeScreenTest.php']],
        'tests' => ['layout-repro' => $run],
        'commands' => [],
    ]) ?? throw new RuntimeException('The evidence could not be read.');
}

it('passes a fails_on_base run when one matching test fails on the start commit and every match passes on the working tree', function (): void {
    $evidence = fails_on_base_evidence([
        'exit_code' => 0,
        'cases' => [
            ['name' => 'it breaks the home screen layout', 'status' => 'passed'],
            ['name' => 'it keeps the home screen layout', 'status' => 'passed'],
        ],
        'base_placed' => true,
        'base_exit_code' => 1,
        'base_cases' => [
            ['name' => 'it breaks the home screen layout', 'status' => 'failed'],
            ['name' => 'it keeps the home screen layout', 'status' => 'passed'],
            ['name' => 'it keeps the home screen layout on a phone', 'status' => 'skipped'],
        ],
    ]);

    expect(TaskDeliverableVerifier::failures([fails_on_base_deliverable()], $evidence))->toBe([]);
});

it('names a fails_on_base pass or skip when no matching test fails on the start commit', function (array $baseCases, string $reason): void {
    $evidence = fails_on_base_evidence([
        'exit_code' => 0,
        'cases' => array_map(static fn (array $case): array => ['name' => $case['name'], 'status' => 'passed'], $baseCases),
        'base_placed' => true,
        'base_exit_code' => 0,
        'base_cases' => $baseCases,
    ]);

    expect(TaskDeliverableVerifier::failures([fails_on_base_deliverable()], $evidence))->toBe(["layout-repro (test): {$reason}"]);
})->with([
    'a pass' => [[['name' => 'it keeps the home screen layout', 'status' => 'passed']], 'The test "it keeps the home screen layout" passes on the start commit, so it does not reproduce the bug.'],
    'a skip' => [[['name' => 'it keeps the home screen layout', 'status' => 'skipped']], 'The test "it keeps the home screen layout" was skipped on the start commit.'],
    'a pass and a skip' => [[
        ['name' => 'it keeps the home screen layout', 'status' => 'passed'],
        ['name' => 'it keeps the home screen layout on a phone', 'status' => 'skipped'],
    ], 'The test "it keeps the home screen layout" passes on the start commit, so it does not reproduce the bug. The test "it keeps the home screen layout on a phone" was skipped on the start commit.'],
]);

it('reports the diff, the base run, and the working tree together when a fails_on_base test misses each one', function (): void {
    $evidence = fails_on_base_evidence([
        'exit_code' => 1,
        'cases' => [['name' => 'it keeps the home screen layout', 'status' => 'failed']],
        'base_placed' => true,
        'base_exit_code' => 0,
        'base_cases' => [['name' => 'it keeps the home screen layout', 'status' => 'passed']],
    ], [['status' => 'M', 'path' => 'apps/gateway/tests/Feature/OtherTest.php']]);

    expect(TaskDeliverableVerifier::failures([fails_on_base_deliverable()], $evidence))->toBe([
        'layout-repro (test): apps/gateway/tests/Feature/HomeScreenTest.php is not added or modified in the subtask\'s diff. The test "it keeps the home screen layout" passes on the start commit, so it does not reproduce the bug. Orbit ran apps/gateway/tests/Feature/HomeScreenTest.php, and "it keeps the home screen layout" failed.',
    ]);
});

it('uses the documented base-run sentence when the start commit run does not execute the named test', function (array $run, string $reason): void {
    $evidence = fails_on_base_evidence([
        'exit_code' => 0,
        'cases' => [['name' => 'it keeps the home screen layout', 'status' => 'passed']],
        ...$run,
    ]);

    expect(TaskDeliverableVerifier::failures([fails_on_base_deliverable()], $evidence))->toBe(["layout-repro (test): {$reason}"]);
})->with([
    'not placed' => [['base_placed' => false], 'Orbit did not place apps/gateway/tests/Feature/HomeScreenTest.php on the start commit, so the base run did not start.'],
    'placed but not run' => [['base_placed' => true, 'base_exit_code' => 127], 'Orbit did not run apps/gateway/tests/Feature/HomeScreenTest.php on the start commit (exit code 127).'],
    'no matching name' => [['base_placed' => true, 'base_exit_code' => 2, 'base_cases' => [['name' => 'it renders', 'status' => 'passed']]], 'Orbit ran apps/gateway/tests/Feature/HomeScreenTest.php on the start commit (exit code 2), and no test name contains "home screen layout".'],
    'no base evidence' => [[], 'Orbit did not place apps/gateway/tests/Feature/HomeScreenTest.php on the start commit, so the base run did not start.'],
]);

it('keeps the single working-tree run when fails_on_base is false', function (): void {
    $deliverable = TaskDeliverable::fromArray([
        'id' => 'layout-repro',
        'type' => 'test',
        'description' => 'The layout',
        'project' => 'apps/gateway',
        'file' => 'tests/Feature/HomeScreenTest.php',
        'name' => 'home screen layout',
    ]);
    $evidence = fails_on_base_evidence([
        'exit_code' => 0,
        'cases' => [['name' => 'it keeps the home screen layout', 'status' => 'passed']],
        'base_placed' => true,
        'base_exit_code' => 0,
        'base_cases' => [['name' => 'it keeps the home screen layout', 'status' => 'passed']],
    ]);

    expect(TaskDeliverableVerifier::failures([$deliverable], $evidence))->toBe([]);
});

it('normalizes a relative path and joins a project and file', function (): void {
    expect(TaskDeliverable::join('.', 'tests/ExportTest.php'))->toBe('tests/ExportTest.php')
        ->and(TaskDeliverable::join('./apps/gateway/', './tests/ExportTest.php'))->toBe('apps/gateway/tests/ExportTest.php')
        ->and(TaskDeliverable::relative('.'))->toBe('');
});
