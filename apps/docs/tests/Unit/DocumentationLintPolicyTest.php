<?php

declare(strict_types=1);

use App\Documentation\DocumentationLintPolicy;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\LintResult;

it('ignores only the configured Librarian findings', function (): void {
    $policy = new DocumentationLintPolicy(['librarian.core_docs_structure']);
    $result = $policy->apply(new LintResult([
        new Finding(
            path: 'docs/mission.md',
            line: 4,
            severity: FindingSeverity::Error,
            rule: 'librarian.core_docs_structure',
            message: 'Expected fixed headings.',
        ),
        new Finding(
            path: 'docs/mission.md',
            line: 8,
            severity: FindingSeverity::Error,
            rule: 'librarian.links',
            message: 'Broken local link.',
        ),
    ]));

    expect($result->findings)
        ->toHaveCount(1)
        ->and($result->findings[0]->rule)
        ->toBe('librarian.links');
});

it('drops legacy prose findings only for decision records below the configured start', function (): void {
    $policy = new DocumentationLintPolicy([], ['librarian.document_complexity'], 20);
    $finding = static fn (string $path, string $rule): Finding => new Finding(
        path: $path,
        line: 1,
        severity: FindingSeverity::Warning,
        rule: $rule,
        message: 'Dense.',
    );

    $result = $policy->apply(new LintResult([
        $finding('docs/decisions/0009-old.md', 'librarian.document_complexity'),
        $finding('docs/decisions/0020-new.md', 'librarian.document_complexity'),
        $finding('docs/reference/apps.md', 'librarian.document_complexity'),
        $finding('docs/decisions/0009-old.md', 'librarian.links'),
    ]));

    expect(array_map(
        static fn (Finding $finding): string => $finding->path.' '.$finding->rule,
        $result->findings,
    ))->toBe([
        'docs/decisions/0020-new.md librarian.document_complexity',
        'docs/reference/apps.md librarian.document_complexity',
        'docs/decisions/0009-old.md librarian.links',
    ]);
});
