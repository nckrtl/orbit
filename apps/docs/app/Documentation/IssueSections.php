<?php

declare(strict_types=1);

namespace App\Documentation;

use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;

/**
 * Checks the bullet shape inside each issue section.
 */
final readonly class IssueSections
{
    private const string CRITERION_PATTERN = '/^- \[[ x]\] \S.*\. Proof: \S/';

    public function __construct(
        private string $rule,
    ) {}

    /**
     * @param  list<array{level: int, text: string, line: int}>  $sections
     * @return list<Finding>
     */
    public function findings(string $path, DecisionRecordFile $record, array $sections): array
    {
        $findings = [];

        foreach ($sections as $heading) {
            $body = $record->sectionLines($heading['line']);

            if ($body === []) {
                $findings[] = $this->error($path, $heading['line'], "The section [{$heading['text']}] is empty.");

                continue;
            }

            foreach ($body as $line => $text) {
                $message = $this->bulletMessage($heading['text'], trim($text));

                if ($message !== null) {
                    $findings[] = $this->error($path, $line, $message);
                }
            }
        }

        return $findings;
    }

    private function bulletMessage(string $section, string $text): ?string
    {
        return match (true) {
            $section === 'Scope' && preg_match('/^- (In|Out): \S/', $text) !== 1
                => 'Scope bullets are shaped `- In: <change>` or `- Out: <unchanged behavior>`.',
            $section === 'Acceptance' && preg_match(self::CRITERION_PATTERN, $text) !== 1
                => 'Acceptance items are shaped `- [ ] <criterion>. Proof: <action>.`.',
            $section === 'Readiness' && ! str_starts_with($text, '- ') => 'Readiness items are bullets.',
            default => null,
        };
    }

    private function error(string $path, int $line, string $message): Finding
    {
        return new Finding(
            path: $path,
            line: $line,
            severity: FindingSeverity::Error,
            rule: $this->rule,
            message: $message,
        );
    }
}
