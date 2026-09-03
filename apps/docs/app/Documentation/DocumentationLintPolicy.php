<?php

declare(strict_types=1);

namespace App\Documentation;

use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\LintResult;
use LogicException;

final readonly class DocumentationLintPolicy
{
    /**
     * @param  list<string>  $ignoredRules  rule names dropped everywhere
     * @param  list<string>  $legacyDecisionRules  rule names dropped for decision records numbered below $decisionRulesFrom, which are immutable
     */
    public function __construct(
        public array $ignoredRules,
        public array $legacyDecisionRules = [],
        public int $decisionRulesFrom = 0,
    ) {
        foreach ([...$ignoredRules, ...$legacyDecisionRules] as $rule) {
            if ($rule === '') {
                throw new LogicException('Ignored Librarian rule names must be non-empty strings.');
            }
        }
    }

    public function apply(LintResult $result): LintResult
    {
        return new LintResult(array_values(array_filter(
            $result->findings,
            fn (Finding $finding): bool => (
                ! in_array($finding->rule, $this->ignoredRules, true) && ! $this->isLegacyDecisionFinding($finding)
            ),
        )));
    }

    private function isLegacyDecisionFinding(Finding $finding): bool
    {
        if (! in_array($finding->rule, $this->legacyDecisionRules, true)) {
            return false;
        }

        if (preg_match('#^docs/decisions/(\d{4})-#', $finding->path, $matches) !== 1) {
            return false;
        }

        return (int) $matches[1] < $this->decisionRulesFrom;
    }
}
