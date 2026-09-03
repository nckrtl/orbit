<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Documentation\DecisionRecordAffects;
use App\Documentation\DecisionRecordFile;
use App\Documentation\DecisionRecordTemplate;
use HardImpact\Librarian\Docs\MarkdownSnapshot;
use HardImpact\Librarian\Linting\GroupedRule;

/**
 * Enforces the ADR template on records numbered at or above the configured start.
 */
final readonly class DecisionRecordStructureRule implements GroupedRule
{
    public const string RULE = 'orbit.adr_structure';

    private DecisionRecordTemplate $template;

    private DecisionRecordAffects $affects;

    /** @param list<string> $components */
    public function __construct(
        private MarkdownSnapshot $snapshot,
        private int $fromNumber,
        array $components,
    ) {
        $this->template = new DecisionRecordTemplate(self::RULE);
        $this->affects = new DecisionRecordAffects(self::RULE, $components);
    }

    public function group(): string
    {
        return 'structure';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->snapshot->capture() as $relativePath => $contents) {
            $record = DecisionRecordFile::fromContents($relativePath, $contents);

            if ($record === null || $record->number < $this->fromNumber) {
                continue;
            }

            array_push($findings, ...$this->template->findings($record), ...$this->affects->findings($record));
        }

        return $findings;
    }
}
