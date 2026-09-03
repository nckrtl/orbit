<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Documentation\BlockedPhrases;
use App\Documentation\MarkdownProse;
use HardImpact\Librarian\Docs\MarkdownSnapshot;
use HardImpact\Librarian\Linting\GroupedRule;

/**
 * Rejects change narration in maintained documentation outside decision records.
 */
final readonly class DocumentationNarrativeRule implements GroupedRule
{
    public const string RULE = 'orbit.docs_narrative';

    public function __construct(
        private MarkdownSnapshot $snapshot,
        private BlockedPhrases $phrases,
    ) {}

    public function group(): string
    {
        return 'prose';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->snapshot->capture() as $relativePath => $contents) {
            if (str_starts_with($relativePath, 'decisions/') || str_starts_with($relativePath, 'generated/')) {
                continue;
            }

            array_push($findings, ...$this->phrases->scan(
                MarkdownProse::lines($contents),
                "docs/{$relativePath}",
                self::RULE,
            ));
        }

        return $findings;
    }
}
