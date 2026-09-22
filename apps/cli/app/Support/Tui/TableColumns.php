<?php

declare(strict_types=1);

namespace App\Support\Tui;

use App\Support\Console\TerminalText;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Layout\Constraint\LengthConstraint;
use PhpTui\Tui\Layout\Constraint\MinConstraint;
use PhpTui\Tui\Layout\Constraint\PercentageConstraint;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;

/** A finite budget for the TUI's one-line rows, including every complete cell. */
final readonly class TableColumns
{
    /** @param list<int> $widths */
    private function __construct(public int $requiredWidth, public array $widths) {}

    /**
     * @param  list<string>  $headers
     * @param  list<TableRow>  $rows
     * @param  list<Constraint>  $preferences
     */
    public static function resolve(int $outerWidth, array $headers, array $rows, array $preferences, int $selector = 2): self
    {
        $minimum = [];
        $preferred = [];
        foreach ($preferences as $index => $preference) {
            $natural = max(1, TerminalText::width(TerminalText::safe($headers[$index] ?? '')));
            foreach ($rows as $row) {
                $natural = max($natural, TerminalText::width(self::text($row->getCell($index))));
            }
            $minimum[] = max($natural, $preference instanceof MinConstraint ? $preference->min : 1);
            $preferred[] = max($minimum[$index], match (true) {
                $preference instanceof LengthConstraint => $preference->length,
                $preference instanceof PercentageConstraint => (int) floor(max(0, $outerWidth - 2) * $preference->percentage / 100),
                default => $minimum[$index],
            });
        }

        $overhead = 2 + $selector + max(0, count($preferences) - 1);
        $required = $overhead + array_sum($minimum);
        if ($outerWidth < $required || $minimum === []) {
            return new self($required, []);
        }

        $widths = $minimum;
        $remaining = $outerWidth - $required;
        while ($remaining > 0) {
            $deficits = array_map(static fn (int $width, int $target): int => $target - $width, $widths, $preferred);
            $largest = max($deficits);
            $index = $largest > 0 ? (int) array_search($largest, $deficits, true) : count($widths) - 1;
            $widths[$index]++;
            $remaining--;
        }

        return new self($required, $widths);
    }

    /** @return list<Constraint> */
    public function constraints(): array
    {
        return array_map(Constraint::length(...), $this->widths);
    }

    public static function text(?TableCell $cell): string
    {
        return $cell === null ? '' : TerminalText::safe(implode("\n", array_map(
            static fn (Line $line): string => implode('', array_map(static fn (Span $span): string => $span->content, iterator_to_array($line))),
            $cell->content->lines,
        )));
    }
}
