<?php

declare(strict_types=1);

namespace App\Documentation;

use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Markdown\ProseSegmenter;
use LogicException;

/**
 * Finds hedging and deferral phrases in prose lines.
 */
final readonly class BlockedPhrases
{
    /** @param list<string> $phrases */
    public function __construct(
        private array $phrases,
    ) {
        foreach ($phrases as $phrase) {
            if ($phrase === '' || $phrase !== strtolower($phrase)) {
                throw new LogicException('Blocked phrases must be non-empty lowercase strings.');
            }
        }
    }

    /**
     * @param  array<int, string>  $lines  prose lines keyed by 1-based line number, fenced code already removed
     * @return list<Finding>
     */
    public function scan(array $lines, string $path, string $rule): array
    {
        $findings = [];

        foreach ($lines as $lineNumber => $line) {
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }

            $prose = strtolower(ProseSegmenter::cleanProse($line));

            foreach ($this->phrases as $phrase) {
                if (preg_match('/(?<![a-z])'.preg_quote($phrase, '/').'(?![a-z])/', $prose) !== 1) {
                    continue;
                }

                $findings[] = new Finding(
                    path: $path,
                    line: $lineNumber,
                    severity: FindingSeverity::Error,
                    rule: $rule,
                    message: "Blocked phrase `{$phrase}`. Name the actor, the condition, and the observable result instead.",
                );
            }
        }

        return $findings;
    }
}
