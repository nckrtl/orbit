<?php

declare(strict_types=1);

namespace App\Documentation;

use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;

/**
 * Checks the title, section order, section presence, and Status line of a record.
 */
final readonly class DecisionRecordTemplate
{
    public const array SECTIONS = ['Status', 'Context', 'Decision', 'Rejected alternatives', 'Consequences', 'Affects'];

    private const string STATUS_PATTERN = '/^(Proposed|Accepted on \d{4}-\d{2}-\d{2})\./';

    public function __construct(
        private string $rule,
    ) {}

    /** @return list<Finding> */
    public function findings(DecisionRecordFile $record): array
    {
        $headings = $record->headings();
        $title = $headings[0] ?? null;

        if (
            $title === null
            || $title['level'] !== 1
            || preg_match('/^ADR (\d{4}): \S/', $title['text'], $matches) !== 1
        ) {
            return [$this->error(
                $record,
                $title['line'] ?? 1,
                'The first line must be an H1 shaped `# ADR NNNN: <title>`.',
            )];
        }

        $findings = [];

        if ((int) $matches[1] !== $record->number) {
            $findings[] = $this->error(
                $record,
                $title['line'],
                "The H1 number `{$matches[1]}` must match the filename number.",
            );
        }

        $sections = array_values(array_filter($headings, static fn (array $heading): bool => $heading['level'] === 2));

        if (array_column($sections, 'text') !== self::SECTIONS) {
            $findings[] = $this->error(
                $record,
                $sections[0]['line'] ?? $title['line'],
                'Expected exactly these H2 sections in order: '.implode(', ', self::SECTIONS).'.',
            );

            return $findings;
        }

        foreach ($sections as $heading) {
            $lines = $record->sectionLines($heading['line']);

            if ($lines === []) {
                $findings[] = $this->error(
                    $record,
                    $heading['line'],
                    "The required section [{$heading['text']}] is empty.",
                );
            } elseif ($heading['text'] === 'Status' && preg_match(self::STATUS_PATTERN, trim(reset($lines))) !== 1) {
                $findings[] = $this->error(
                    $record,
                    (int) key($lines),
                    'Status must start with `Proposed.` or `Accepted on YYYY-MM-DD.`.',
                );
            }
        }

        return $findings;
    }

    private function error(DecisionRecordFile $record, int $line, string $message): Finding
    {
        return new Finding(
            path: $record->docsPath(),
            line: $line,
            severity: FindingSeverity::Error,
            rule: $this->rule,
            message: $message,
        );
    }
}
