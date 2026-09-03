<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Documentation\DecisionRecordFile;
use HardImpact\Librarian\Docs\MarkdownSnapshot;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;
use HardImpact\Librarian\Markdown\ProseSegmenter;
use LogicException;

/**
 * Rejects hedging and deferral phrases in records numbered at or above the configured start.
 */
final readonly class DecisionRecordLanguageRule implements GroupedRule
{
    public const string RULE = 'orbit.adr_language';

    /** @param list<string> $phrases */
    public function __construct(
        private MarkdownSnapshot $snapshot,
        private int $fromNumber,
        private array $phrases,
    ) {
        foreach ($phrases as $phrase) {
            if ($phrase === '' || $phrase !== strtolower($phrase)) {
                throw new LogicException('ADR blocked phrases must be non-empty lowercase strings.');
            }
        }
    }

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

            foreach ($record->proseLines() as $lineNumber => $line) {
                if (preg_match('/^\s*#/', $line) === 1) {
                    continue;
                }

                $prose = strtolower(ProseSegmenter::cleanProse($line));

                foreach ($this->phrases as $phrase) {
                    if (preg_match('/(?<![a-z])'.preg_quote($phrase, '/').'(?![a-z])/', $prose) !== 1) {
                        continue;
                    }

                    $findings[] = new Finding(
                        path: $record->docsPath(),
                        line: $lineNumber,
                        severity: FindingSeverity::Error,
                        rule: self::RULE,
                        message: "Blocked phrase `{$phrase}`. Name the actor, the condition, and the observable result instead.",
                    );
                }
            }
        }

        return $findings;
    }
}
