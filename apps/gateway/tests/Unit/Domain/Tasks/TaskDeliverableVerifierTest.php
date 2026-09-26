<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskDeliverable;
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

it('normalizes a relative path and joins a project and file', function (): void {
    expect(TaskDeliverable::join('.', 'tests/ExportTest.php'))->toBe('tests/ExportTest.php')
        ->and(TaskDeliverable::join('./apps/gateway/', './tests/ExportTest.php'))->toBe('apps/gateway/tests/ExportTest.php')
        ->and(TaskDeliverable::relative('.'))->toBe('');
});
