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
