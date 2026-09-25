<?php

declare(strict_types=1);

namespace App\Support\Console;

use App\Support\Console\Renderers\TableLayout;
use Laravel\Prompts\Table;
use Symfony\Component\Console\Output\BufferedOutput;

/** Rendered strings are terminal bytes; callers write them with OUTPUT_RAW. */
final readonly class HumanRenderer
{
    public function __construct(private ConsoleMode $mode) {}

    /** @param array<string, scalar|null|list<scalar|null>> $orderedFields */
    public function detail(string $title, array $orderedFields): string
    {
        if ($this->mode->machine) {
            return '';
        }

        $columns = $this->mode->columns;

        if ($columns < 7) {
            return $this->lines($title).$this->plainFields($orderedFields);
        }

        $lines = [''];

        foreach (TerminalText::wrap(TerminalText::safe($title), $columns - 3) as $index => $line) {
            $lines[] = $this->connector($index === 0 ? '┌' : '│').'  '.$line;
        }

        $labels = array_map(TerminalText::safe(...), array_keys($orderedFields));
        $labelWidth = min(max([1, ...array_map(TerminalText::width(...), $labels)]), max(1, intdiv($columns - 6, 2)));
        $valueWidth = $columns - 6 - $labelWidth;
        $last = count($orderedFields) - 1;

        foreach (array_values($orderedFields) as $index => $value) {
            $lines[] = $this->connector('│');
            $label = $labels[$index];
            $text = TerminalText::displayValue($value);
            $start = $index === $last ? '└' : '├';
            $continuation = $index === $last ? ' ' : '│';

            if (TerminalText::minimumWidth($label) > $labelWidth || TerminalText::minimumWidth($text) > $valueWidth) {
                $parts = [...TerminalText::wrap($label, $columns - 3), ...TerminalText::wrapWords($text, $columns - 3)];

                foreach ($parts as $line => $part) {
                    $lines[] = $this->connector($line === 0 ? $start : $continuation).'  '.$part;
                }

                continue;
            }

            $labelLines = TerminalText::wrap($label, $labelWidth);
            $valueLines = TerminalText::wrapWords($text, $valueWidth);

            for ($line = 0; $line < max(count($labelLines), count($valueLines)); $line++) {
                $lines[] = $this->connector($line === 0 ? $start : $continuation).'  '
                    .TerminalText::pad($labelLines[$line] ?? '', $labelWidth).'   '.($valueLines[$line] ?? '');
            }
        }

        if ($orderedFields === []) {
            $lines[] = $this->connector('└');
        }

        return implode(PHP_EOL, $lines).PHP_EOL.PHP_EOL;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<scalar|null>>  $rows
     */
    public function table(array $headers, array $rows, string $emptyMessage = 'No matching records found.'): string
    {
        if ($this->mode->machine) {
            return '';
        }

        if ($rows === []) {
            return $this->lines($emptyMessage);
        }

        $headers = array_map(static fn (string $header): string => mb_strtoupper($header, 'UTF-8'), $headers);

        if ($this->mode->columns < TableLayout::minimumWidth($headers, $rows)) {
            $output = '';

            foreach ($rows as $index => $row) {
                $output .= ($index === 0 ? '' : PHP_EOL).$this->lines('Record '.($index + 1));

                foreach ($headers as $column => $header) {
                    $output .= $this->lines(TerminalText::safe($header).': '.TerminalText::displayValue($row[$column] ?? null));
                }
            }

            return $output;
        }

        $output = new BufferedOutput(decorated: $this->mode->decorated);
        $values = array_map(static fn (array $row): array => array_map(TerminalText::displayValue(...), $row), $rows);

        PromptContext::run($this->mode, $output, static function () use ($headers, $values): void {
            new Table($headers, $values)->display();
        });

        return $output->fetch();
    }

    /**
     * @param  list<array{title: string, items: list<array{label: string, fields: array<string, scalar|null|list<scalar|null>>}>}>  $groups
     */
    public function properties(array $groups): string
    {
        if ($this->mode->machine) {
            return '';
        }

        $output = '';

        foreach ($groups as $index => $group) {
            $output .= ($index === 0 ? '' : PHP_EOL).$this->lines($group['title']);

            foreach ($group['items'] as $item) {
                $output .= $this->lines($item['label'], 2);
                $output .= $this->plainFields($item['fields'], 4);
            }
        }

        return $output;
    }

    public function warning(string $safeMessage): string
    {
        if ($this->mode->machine) {
            return '';
        }

        $output = '';

        foreach (TerminalText::wrap(TerminalText::safe($safeMessage), $this->mode->columns) as $line) {
            $output .= TerminalText::style($line, 'orange', $this->mode->decorated).PHP_EOL;
        }

        return $output;
    }

    /** @param array<string, string|list<string>> $safeFields */
    public function failure(string $safeMessage, array $safeFields = [], ?string $requestId = null, ?string $code = null): string
    {
        if ($this->mode->machine) {
            return '';
        }

        $output = '';

        foreach (TerminalText::wrap(TerminalText::safe($safeMessage), $this->mode->columns) as $line) {
            $output .= TerminalText::style($line, 'red', $this->mode->decorated).PHP_EOL;
        }

        foreach ($safeFields as $field => $messages) {
            foreach (is_array($messages) ? $messages : [$messages] as $message) {
                $output .= $this->lines(TerminalText::safe($field).': '.TerminalText::safe($message));
            }
        }

        if ($code !== null) {
            $output .= $this->lines('Code: '.TerminalText::safe($code));
        }

        if ($requestId !== null) {
            $output .= $this->lines('Request ID: '.TerminalText::safe($requestId));
        }

        return $output;
    }

    private function connector(string $connector): string
    {
        return TerminalText::style($connector, 'dim', $this->mode->decorated);
    }

    /** @param array<string, scalar|null|list<scalar|null>> $fields */
    private function plainFields(array $fields, int $indent = 0): string
    {
        $output = '';

        foreach ($fields as $label => $value) {
            $output .= $this->lines(TerminalText::safe($label).': '.TerminalText::displayValue($value), $indent);
        }

        return $output;
    }

    private function lines(string $text, int $indent = 0): string
    {
        $indent = min($indent, max(0, $this->mode->columns - TerminalText::minimumWidth($text)));
        $prefix = str_repeat(' ', $indent);

        return implode(PHP_EOL, array_map(
            static fn (string $line): string => $prefix.$line,
            TerminalText::wrap(TerminalText::safe($text), $this->mode->columns - $indent),
        )).PHP_EOL;
    }
}
