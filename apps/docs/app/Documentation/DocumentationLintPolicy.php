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
     * @param  list<string>  $decisionIgnoredRules  rule names dropped for every decision record, because the ADR template opens sections with bullets and a one-sentence summary
     */
    public function __construct(
        public array $ignoredRules,
        public array $legacyDecisionRules = [],
        public int $decisionRulesFrom = 0,
        public array $decisionIgnoredRules = [],
    ) {
        foreach ([...$ignoredRules, ...$legacyDecisionRules, ...$decisionIgnoredRules] as $rule) {
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
        if (preg_match('#^docs/decisions/(\d{4})-#', $finding->path, $matches) !== 1) {
            return false;
        }

        if (in_array($finding->rule, $this->decisionIgnoredRules, true)) {
            return true;
        }

        return (
            in_array($finding->rule, $this->legacyDecisionRules, true)
            && (int) $matches[1] < $this->decisionRulesFrom
        );
    }
}
