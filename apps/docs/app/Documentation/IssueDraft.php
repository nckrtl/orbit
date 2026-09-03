<?php

declare(strict_types=1);

namespace App\Documentation;

use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;

/**
 * Checks a drafted Linear issue description against the creating-issues template.
 */
final readonly class IssueDraft
{
    public const string RULE = 'orbit.issue_structure';

    public const string LANGUAGE_RULE = 'orbit.issue_language';

    private const array SECTIONS = ['Readiness', 'Scope', 'Acceptance'];

    public function __construct(
        private BlockedPhrases $phrases,
    ) {}

    /** @return list<Finding> */
    public function findings(string $path, string $contents, bool $parent): array
    {
        $record = DecisionRecordFile::draft($contents);
        $lines = $record->proseLines();
        $headings = $record->headings();
        $findings = $this->phrases->scan($lines, $path, self::LANGUAGE_RULE);

        if (! $this->hasOutcome($lines, $headings[0]['line'] ?? PHP_INT_MAX)) {
            $findings[] = $this->error(
                $path,
                1,
                'The description must open with the outcome paragraph before any heading.',
            );
        }

        $sections = array_values(array_filter($headings, static fn (array $heading): bool => $heading['level'] === 2));
        $names = array_column($sections, 'text');
        $expected = array_values(array_filter(self::SECTIONS, static fn (string $section): bool => $section
            === 'Readiness'
                ? in_array('Readiness', $names, true)
                : $section !== 'Acceptance' || ! $parent));

        if ($names !== $expected) {
            $findings[] = $this->error(
                $path,
                $sections[0]['line'] ?? 1,
                'Expected H2 sections in order: '
                .implode(', ', $expected)
                .'. Readiness is optional; a parent has no Acceptance.',
            );

            return $findings;
        }

        array_push($findings, ...new IssueSections(self::RULE)->findings($path, $record, $sections));

        return $findings;
    }

    /** @param array<int, string> $lines */
    private function hasOutcome(array $lines, int $firstHeadingLine): bool
    {
        foreach ($lines as $lineNumber => $line) {
            if ($lineNumber >= $firstHeadingLine) {
                return false;
            }

            if (trim($line) !== '') {
                return true;
            }
        }

        return false;
    }

    private function error(string $path, int $line, string $message): Finding
    {
        return new Finding(
            path: $path,
            line: $line,
            severity: FindingSeverity::Error,
            rule: self::RULE,
            message: $message,
        );
    }
}
