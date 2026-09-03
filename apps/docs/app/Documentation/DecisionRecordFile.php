<?php

declare(strict_types=1);

namespace App\Documentation;

/**
 * One numbered architecture decision record under docs/decisions.
 */
final readonly class DecisionRecordFile
{
    /** @param list<string> $lines */
    private function __construct(
        public string $relativePath,
        public int $number,
        public array $lines,
    ) {}

    public static function fromContents(string $relativePath, string $contents): ?self
    {
        if (preg_match('#^decisions/(\d{4})-[a-z0-9-]+\.md$#', $relativePath, $matches) !== 1) {
            return null;
        }

        return new self($relativePath, (int) $matches[1], preg_split('/\R/', $contents) ?: []);
    }

    /**
     * A markdown draft that is not a numbered record, for the same line and heading helpers.
     */
    public static function draft(string $contents): self
    {
        return new self('', 0, preg_split('/\R/', $contents) ?: []);
    }

    public function docsPath(): string
    {
        return "docs/{$this->relativePath}";
    }

    /**
     * Headings with their line numbers, ignoring fenced code.
     *
     * @return list<array{level: int, text: string, line: int}>
     */
    public function headings(): array
    {
        $headings = [];

        foreach ($this->proseLines() as $lineNumber => $line) {
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/u', $line, $matches) !== 1) {
                continue;
            }

            $headings[] = ['level' => strlen($matches[1]), 'text' => trim($matches[2]), 'line' => $lineNumber];
        }

        return $headings;
    }

    /**
     * Non-empty body lines of the section opened by the heading at $headingLine.
     *
     * @return array<int, string> keyed by line number
     */
    public function sectionLines(int $headingLine): array
    {
        $lines = [];

        foreach ($this->proseLines() as $lineNumber => $line) {
            if ($lineNumber <= $headingLine) {
                continue;
            }

            if (preg_match('/^#{1,6}\s/', $line) === 1) {
                break;
            }

            if (trim($line) !== '') {
                $lines[$lineNumber] = $line;
            }
        }

        return $lines;
    }

    /**
     * Lines outside fenced code blocks, keyed by 1-based line number.
     *
     * @return array<int, string>
     */
    public function proseLines(): array
    {
        $lines = [];
        $inFence = false;

        foreach ($this->lines as $index => $line) {
            if (preg_match('/^\s*```/', $line) === 1) {
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
