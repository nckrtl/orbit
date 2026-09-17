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
     * @param  list<string>  $generatedPaths  path prefixes of rendered files, whose text is linted at its source
     * @param  list<string>  $commandIgnoredRules  rule names dropped for command sources and the CLI pages rendered from them, which open sections with the tables and steps an agent scans
     */
    public function __construct(
        public array $ignoredRules,
        public array $legacyDecisionRules = [],
        public int $decisionRulesFrom = 0,
        public array $decisionIgnoredRules = [],
        public array $commandIgnoredRules = [],
        public array $generatedPaths = [],
    ) {
        foreach ([...$ignoredRules, ...$legacyDecisionRules, ...$decisionIgnoredRules, ...$commandIgnoredRules] as $rule) {
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
                ! $this->isGenerated($finding)
                && ! in_array($finding->rule, $this->ignoredRules, true)
                && ! $this->isLegacyDecisionFinding($finding)
                && ! $this->isCommandFinding($finding)
            ),
        )));
    }

    private function isGenerated(Finding $finding): bool
    {
        return array_any($this->generatedPaths, static fn (string $prefix): bool => str_starts_with($finding->path, $prefix));
    }

    private function isCommandFinding(Finding $finding): bool
    {
        return (str_starts_with($finding->path, 'docs/commands/') || str_starts_with($finding->path, 'docs/cli/'))
            && in_array($finding->rule, $this->commandIgnoredRules, true);
    }

    private function isLegacyDecisionFinding(Finding $finding): bool
    {
        if (preg_match('#^docs/decisions/(\d{4})-#', $finding->path, $matches) !== 1) {
            return false;
        }

        if (in_array($finding->rule, $this->decisionIgnoredRules, true)) {
            return true;
        }

        return
            in_array($finding->rule, $this->legacyDecisionRules, true)
            && (int) $matches[1] < $this->decisionRulesFrom;
    }
}
