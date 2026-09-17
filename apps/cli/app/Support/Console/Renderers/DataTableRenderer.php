<?php

declare(strict_types=1);

namespace App\Support\Console\Renderers;

use App\Support\Console\PromptAborted;
use App\Support\Console\SearchableDataTablePrompt;
use App\Support\Console\TerminalText;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Themes\Contracts\Scrolling;
use Laravel\Prompts\Themes\Default\Renderer;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class DataTableRenderer extends Renderer implements Scrolling
{
    public function __invoke(DataTablePrompt $prompt): string
    {
        if ($prompt->state === 'submit') {
            // The selection is ephemeral: the result the caller renders takes its place.
            return '';
        }

        $mode = TableTheme::mode();
        $columns = min($mode->columns, $prompt->terminal()->cols());
        $headers = array_map(static fn (string|array $header): string => is_array($header) ? implode(' ', $header) : $header, $prompt->headers);
        $minimum = TableLayout::minimumWidth($headers, $prompt->rows);

        if ($columns < $minimum) {
            throw new PromptAborted("Selection requires {$minimum} terminal columns; available width is {$columns}.", 'terminal_too_narrow');
        }

        $widths = TableLayout::widths($headers, $prompt->rows, $columns);
        $heading = TerminalText::wrap(TerminalText::safe($prompt->label), $columns);
        $headerRows = TableLayout::wrapCells($headers, $widths);
        $footer = $this->footer($prompt, $columns);
        $available = $prompt->terminal()->lines() - count($heading) - count($headerRows) - count($footer) - 5;

        if ($available < 1) {
            throw new PromptAborted('The selection headers need more terminal rows. Enlarge the terminal before selecting.', 'terminal_too_short');
        }

        $filtered = $prompt->filteredRows();
        $selectedKey = array_keys($filtered)[$prompt->highlighted ?? 0] ?? null;

        if ($prompt->state === 'submit' && $selectedKey !== null) {
            $filtered = [$selectedKey => $filtered[$selectedKey]];
        }

        $rows = $this->visibleRows($filtered, $selectedKey, $widths, $available, $prompt->scroll);
        $lines = ['', ...$heading, ...$this->searchBox($prompt, $columns), TableLayout::border($widths, '┌', '┬', '┐', $mode->decorated)];

        foreach ($headerRows as $cells) {
            $lines[] = TableLayout::row($cells, $widths, $mode->decorated, header: true);
        }

        $lines[] = TableLayout::border($widths, '├', '┼', '┤', $mode->decorated);

        foreach ($rows as $key => $wrapped) {
            foreach ($wrapped as $cells) {
                $lines[] = TableLayout::row($cells, $widths, $mode->decorated, selected: $key === $selectedKey && $prompt->state !== 'search');
            }
        }

        $lines[] = TableLayout::border($widths, '└', '┴', '┘', $mode->decorated);

        if ($filtered === []) {
            array_push($lines, ...TerminalText::wrap('No matching records found.', $columns));
        }

        array_push($lines, ...$footer);

        return OutputFormatter::escape(implode(PHP_EOL, $lines).PHP_EOL);
    }

    public function reservedLines(): int
    {
        return 11;
    }

    /**
     * The filter box stays visible; typing narrows the rows at once.
     *
     * @return list<string>
     */
    private function searchBox(DataTablePrompt $prompt, int $columns): array
    {
        $mode = TableTheme::mode();
        $text = $prompt instanceof SearchableDataTablePrompt ? $prompt->filterText() : $prompt->searchValue();
        $typed = $text === ''
            ? TerminalText::style('Type to filter', 'dim', $mode->decorated)
            : TerminalText::safe($text);
        $cursor = TerminalText::style('▏', 'cyan', $mode->decorated);
        $line = ' '.TerminalText::style('Search', 'dim', $mode->decorated).'  '.$typed.$cursor;

        return [$line, ''];
    }

    /** @return list<string> */
    private function footer(DataTablePrompt $prompt, int $columns): array
    {
        $mode = TableTheme::mode();
        $message = match ($prompt->state) {
            'cancel' => $prompt->cancelMessage,
            'error' => $prompt->error,
            'submit' => 'Selected.',
            'search' => '/ '.TerminalText::plain(str_replace(["\033[7m", "\033[27m"], ['▏', ''], $prompt->searchWithCursor(-1))),
            default => '↑↓ move, type to filter, Enter selects.',
        };
        $style = in_array($prompt->state, ['error', 'cancel'], true) ? 'red' : 'dim';
        $lines = array_map(
            static fn (string $line): string => TerminalText::style($line, $style, $mode->decorated),
            TerminalText::wrap(TerminalText::safe($message), $columns),
        );

        if ($prompt->state !== 'submit' && $prompt->hint !== '' && $prompt->hint !== $message) {
            array_push($lines, ...TerminalText::wrap(TerminalText::safe($prompt->hint), $columns));
        }

        return $lines;
    }

    /**
     * @param  array<int|string, list<string>>  $rows
     * @param  list<int>  $widths
     * @return array<int|string, non-empty-list<list<string>>>
     */
    private function visibleRows(array $rows, int|string|null $selectedKey, array $widths, int $available, int $limit): array
    {
        if ($rows === []) {
            return [];
        }

        $keys = array_keys($rows);
        $selectedIndex = array_search($selectedKey, $keys, true);
        $start = $selectedIndex === false ? 0 : $selectedIndex;
        $visible = [];
        $height = 0;

        for ($index = $start; $index < count($keys) && count($visible) < $limit; $index++) {
            $key = $keys[$index];
            $wrapped = TableLayout::wrapCells($rows[$key], $widths);

            if ($height + count($wrapped) > $available) {
                if ($visible === []) {
                    throw new PromptAborted('The selected row needs more terminal rows to display every field. Enlarge the terminal before selecting.', 'terminal_too_short');
                }

                break;
            }

            $visible[$key] = $wrapped;
            $height += count($wrapped);
        }

        for ($index = $start - 1; $index >= 0 && count($visible) < $limit; $index--) {
            $key = $keys[$index];
            $wrapped = TableLayout::wrapCells($rows[$key], $widths);

            if ($height + count($wrapped) > $available) {
                break;
            }

            $visible = [$key => $wrapped] + $visible;
            $height += count($wrapped);
        }

        return $visible;
    }
}
