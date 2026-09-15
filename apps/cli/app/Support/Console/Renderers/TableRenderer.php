<?php

declare(strict_types=1);

namespace App\Support\Console\Renderers;

use Laravel\Prompts\Table;
use Laravel\Prompts\Themes\Default\Renderer;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class TableRenderer extends Renderer
{
    public function __invoke(Table $table): string
    {
        $mode = TableTheme::mode();
        $headers = array_map(static fn (string|array $header): string => is_array($header) ? implode(' ', $header) : $header, $table->headers);
        $widths = TableLayout::widths($headers, $table->rows, $mode->columns);
        $lines = [TableLayout::border($widths, '┌', '┬', '┐', $mode->decorated)];

        foreach (TableLayout::wrapCells($headers, $widths) as $cells) {
            $lines[] = TableLayout::row($cells, $widths, $mode->decorated, header: true);
        }

        $lines[] = TableLayout::border($widths, '├', '┼', '┤', $mode->decorated);

        foreach ($table->rows as $row) {
            foreach (TableLayout::wrapCells($row, $widths) as $cells) {
                $lines[] = TableLayout::row($cells, $widths, $mode->decorated);
            }
        }

        $lines[] = TableLayout::border($widths, '└', '┴', '┘', $mode->decorated);

        return OutputFormatter::escape(implode(PHP_EOL, $lines).PHP_EOL);
    }
}
