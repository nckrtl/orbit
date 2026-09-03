<?php

declare(strict_types=1);

namespace App\Documentation;

use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;

/**
 * Checks the Affects section fields and validates named components.
 */
final readonly class DecisionRecordAffects
{
    public const array FIELDS = ['Components', 'ADRs', 'Detail', 'Verify'];

    /** @param list<string> $components */
    public function __construct(
        private string $rule,
        private array $components,
    ) {}

    /** @return list<Finding> */
    public function findings(DecisionRecordFile $record): array
    {
        $heading = null;

        foreach ($record->headings() as $candidate) {
            if ($candidate['level'] === 2 && $candidate['text'] === 'Affects') {
                $heading = $candidate;
            }
        }

        if ($heading === null) {
            return [];
        }

        $findings = [];
        $present = [];

        foreach ($record->sectionLines($heading['line']) as $lineNumber => $line) {
            if (preg_match('/^- ([A-Za-z]+): (\S.*)$/', trim($line), $matches) !== 1) {
                continue;
            }

            $present[] = $matches[1];

            if ($matches[1] === 'Components') {
                array_push($findings, ...$this->componentFindings($record, $lineNumber, $matches[2]));
            }
        }

        foreach (array_diff(self::FIELDS, $present) as $field) {
            $findings[] = $this->error($record, $heading['line'], "Affects must list `- {$field}: <value>`.");
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function componentFindings(DecisionRecordFile $record, int $line, string $value): array
    {
        $names = array_map(trim(...), explode(',', $value));

        if ($names === ['none']) {
            return [];
        }

        $findings = [];

        foreach (array_diff($names, $this->components) as $name) {
            $findings[] = $this->error(
                $record,
                $line,
                "Unknown component `{$name}`. Use `none` or a comma-separated subset of: "
                .implode(', ', $this->components)
                .'.',
            );
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
