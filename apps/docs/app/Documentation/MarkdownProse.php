<?php

declare(strict_types=1);

namespace App\Documentation;

/**
 * Splits markdown into prose lines outside fenced code, keyed by 1-based line number.
 */
final class MarkdownProse
{
    /** @return array<int, string> */
    public static function lines(string $contents): array
    {
        $lines = [];
        $inFence = false;

        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inFence = ! $inFence;

                continue;
            }

            if (! $inFence) {
                $lines[$index + 1] = $line;
            }
        }

        return $lines;
    }
}
