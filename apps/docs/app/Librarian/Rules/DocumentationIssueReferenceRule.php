<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Documentation\MarkdownProse;
use HardImpact\Librarian\Docs\MarkdownSnapshot;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;
use HardImpact\Librarian\Markdown\ProseSegmenter;
use LogicException;

/**
 * Rejects a tracker issue identifier in the prose of a maintained page.
 *
 * A page states current behavior, not the work that produced it. Decision
 * records are exempt: they are immutable and name the work they came from.
 */
final readonly class DocumentationIssueReferenceRule implements GroupedRule
{
    public const string RULE = 'orbit.docs_issue_reference';

    /** @param list<string> $prefixes */
    public function __construct(
        private MarkdownSnapshot $snapshot,
        private array $prefixes,
    ) {
        foreach ($prefixes as $prefix) {
            if (preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $prefix) !== 1) {
                throw new LogicException('Issue key prefixes must be uppercase alphanumeric strings.');
            }
        }
    }

    public function group(): string
    {
        return 'prose';
    }

    public function check(): array
    {
        if ($this->prefixes === []) {
            return [];
        }

        $pattern = '/\b('.implode('|', array_map(preg_quote(...), $this->prefixes)).')-\d+\b/';
        $findings = [];

        foreach ($this->snapshot->capture() as $relativePath => $contents) {
            if (str_starts_with($relativePath, 'decisions/') || str_starts_with($relativePath, 'generated/')) {
                continue;
            }

            foreach (MarkdownProse::lines($contents) as $lineNumber => $line) {
                if (preg_match('/^\s*#/', $line) === 1) {
                    continue;
                }

                if (preg_match($pattern, ProseSegmenter::cleanProse($line), $matches) !== 1) {
                    continue;
                }

                $findings[] = new Finding(
                    path: "docs/{$relativePath}",
                    line: $lineNumber,
                    severity: FindingSeverity::Error,
                    rule: self::RULE,
                    message: "Issue identifier `{$matches[0]}` in prose. State the behavior the page describes, not the work that produced it.",
                );
            }
        }

        return $findings;
    }
}
