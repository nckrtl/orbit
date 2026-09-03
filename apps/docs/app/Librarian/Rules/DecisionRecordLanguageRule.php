<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Documentation\BlockedPhrases;
use App\Documentation\DecisionRecordFile;
use HardImpact\Librarian\Docs\MarkdownSnapshot;
use HardImpact\Librarian\Linting\GroupedRule;

/**
 * Rejects hedging and deferral phrases in records numbered at or above the configured start.
 */
final readonly class DecisionRecordLanguageRule implements GroupedRule
{
    public const string RULE = 'orbit.adr_language';

    public function __construct(
        private MarkdownSnapshot $snapshot,
        private int $fromNumber,
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
            $record = DecisionRecordFile::fromContents($relativePath, $contents);

            if ($record === null || $record->number < $this->fromNumber) {
                continue;
            }

            array_push($findings, ...$this->phrases->scan($record->proseLines(), $record->docsPath(), self::RULE));
        }

        return $findings;
    }
}
