<?php

declare(strict_types=1);

namespace App\Support\Console\Renderers;

use App\Support\Console\TerminalText;
use InvalidArgumentException;
use LengthException;

final class TableLayout
{
    /**
     * @param  list<string>  $headers
     * @param  array<int|string, list<scalar|null>>  $rows
     * @return list<int>
     */
    public static function widths(array $headers, array $rows, int $columns): array
    {
        [$minimum, $natural] = self::columnWidths($headers, $rows);
        $remaining = $columns - self::overhead(count($headers)) - array_sum($minimum);

        if ($remaining < 0) {
            throw new LengthException('Table does not fit the terminal width.');
        }

        $widths = $minimum;

        while ($remaining > 0) {
            $changed = false;

            foreach ($widths as $index => $width) {
                if ($remaining > 0 && $width < $natural[$index]) {
                    $widths[$index]++;
                    $remaining--;
                    $changed = true;
                }
            }

            if (! $changed) {
                break;
            }
        }

        return $widths;
    }

    /**
     * @param  list<string>  $headers
     * @param  array<int|string, list<scalar|null>>  $rows
     */
    public static function minimumWidth(array $headers, array $rows): int
    {
        [$minimum] = self::columnWidths($headers, $rows);

        return self::overhead(count($headers)) + array_sum($minimum);
    }

    /**
     * @param  list<scalar|null>  $cells
     * @param  list<int>  $widths
     * @return non-empty-list<list<string>>
     */
    public static function wrapCells(array $cells, array $widths): array
    {
        $columns = [];
        $height = 1;

        foreach ($widths as $index => $width) {
            $lines = TerminalText::wrap(TerminalText::displayValue($cells[$index] ?? null), $width);
            $columns[] = $lines;
            $height = max($height, count($lines));
        }

        $rows = [];

        for ($line = 0; $line < $height; $line++) {
            $rows[] = array_map(static fn (array $column): string => $column[$line] ?? '', $columns);
        }

        return $rows;
    }

    /** @param list<int> $widths */
    public static function border(array $widths, string $left, string $join, string $right, bool $decorated): string
    {
        return ' '.TerminalText::style(
            $left.implode($join, array_map(static fn (int $width): string => str_repeat('─', $width + 2), $widths)).$right,
            'dim',
            $decorated,
        );
    }

    /**
     * @param  list<string>  $cells
     * @param  list<int>  $widths
     */
    public static function row(array $cells, array $widths, bool $decorated, bool $selected = false, bool $header = false): string
    {
        $border = TerminalText::style('│', 'dim', $decorated);
        $parts = [];

        foreach ($widths as $index => $width) {
            $text = ' '.TerminalText::pad($cells[$index] ?? '', $width).' ';
            $parts[] = $selected
                ? TerminalText::style($text, 'inverse', $decorated)
                : TerminalText::style($text, 'dim', $decorated && $header);
        }

        return ($selected ? '›' : ' ').$border.implode($border, $parts).$border;
    }

    /**
     * @param  list<string>  $headers
     * @param  array<int|string, list<scalar|null>>  $rows
     * @return array{list<int>, list<int>}
     */
    private static function columnWidths(array $headers, array $rows): array
    {
        if ($headers === []) {
            throw new InvalidArgumentException('A table requires named columns.');
        }

        foreach ($rows as $row) {
            if (count($row) > count($headers)) {
                throw new InvalidArgumentException('A table row has more values than named columns.');
            }
        }

        $minimum = [];
        $natural = [];

        foreach ($headers as $index => $header) {
            $values = [TerminalText::safe($header)];

            foreach ($rows as $row) {
                $values[] = TerminalText::displayValue($row[$index] ?? null);
            }

            $minimum[] = max(array_map(TerminalText::minimumWidth(...), $values));
            $natural[] = max(1, ...array_map(TerminalText::width(...), $values));
        }

        return [$minimum, $natural];
    }

    private static function overhead(int $columns): int
    {
        return $columns * 3 + 2;
    }
}
