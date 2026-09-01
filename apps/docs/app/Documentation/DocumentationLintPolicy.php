<?php

declare(strict_types=1);

namespace App\Documentation;

use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\LintResult;
use LogicException;

final readonly class DocumentationLintPolicy
{
    /** @param list<string> $ignoredRules */
    public function __construct(
        public array $ignoredRules,
    ) {
        foreach ($ignoredRules as $rule) {
            if ($rule === '') {
                throw new LogicException('Ignored Librarian rule names must be non-empty strings.');
            }
        }
    }

    public function apply(LintResult $result): LintResult
    {
        return new LintResult(array_values(array_filter(
            $result->findings,
            fn (Finding $finding): bool => ! in_array($finding->rule, $this->ignoredRules, true),
        )));
    }
}
