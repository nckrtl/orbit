<?php

declare(strict_types=1);

namespace App\Documentation;

/** Checks plan structure, without judging acceptance coverage or proof quality. */
final class FeaturePlan
{
    /** @return list<string> */
    public function findings(string $contents, string $template, string $issue, string $flow): array
    {
        $lines = MarkdownProse::lines($contents);
        $prose = implode("\n", $lines);
        $errors = [];
        preg_match('/^Plan format: (\d+)$/m', $template, $format);
        $headers = [
            'Plan format' => preg_quote($format[1], '/'),
            'Issue' => preg_quote($issue, '/'),
            'Flow' => preg_quote($flow, '/'),
            'Review verdict' => '(?:PENDING|PASS|FIX|BLOCK)',
        ];
        $preamble = explode("\n## ", $prose, 2)[0];
        foreach ($headers as $name => $pattern) {
            if (preg_match_all('/^'.preg_quote($name, '/').':.*$/m', $preamble) !== 1
                || preg_match('/^'.preg_quote($name, '/').': '.$pattern.'$/m', $preamble) !== 1) {
                $errors[] = "Use the current template's {$name} header with the assigned issue and flow.";
            }
        }

        preg_match_all('/^## (.+)$/m', $template, $required);
        preg_match_all('/^## (.+)$/m', $prose, $actual);
        $names = array_values(array_filter($actual[1], static fn (string $name): bool => in_array($name, $required[1], true)));
        if ($names !== $required[1]) {
            $errors[] = 'Required sections must appear once in template order: '.implode(', ', $required[1]).'.';
        }

        $sections = [];
        $section = '';
        foreach ($lines as $number => $line) {
            if (preg_match('/^## (.+)$/', $line, $heading) === 1) {
                $section = $heading[1];
            } else {
                $sections[$section] = ($sections[$section] ?? '').$line."\n";
            }
            if ($this->placeholder($line)) {
                $errors[] = "Line {$number}: replace the unfinished placeholder.";
            }
        }
        foreach ($required[1] as $name) {
            if ($name !== 'Review findings' && trim($sections[$name] ?? '') === '') {
                $errors[] = "Fill {$name}; use none with a reason where appropriate.";
            }
        }

        $boundaries = $sections['Code boundaries'] ?? '';
        if (preg_match('/^In:\s*\n(.+?)^Out:\s*\n(.+)/ms', $boundaries, $scope) !== 1
            || trim($scope[1]) === '' || trim($scope[2]) === '') {
            $errors[] = 'Code boundaries needs nonempty In: and Out: lists.';
        }
        if (preg_match('/^\d+\.\s+\S/m', $sections['Implementation order'] ?? '') !== 1) {
            $errors[] = 'Implementation order needs at least one numbered step.';
        }

        $rows = [];
        $tableEnded = false;
        foreach (explode("\n", $sections['Acceptance map'] ?? '') as $line) {
            if (trim($line) === '') {
                $tableEnded = $rows !== [];

                continue;
            }
            if ($tableEnded || ! str_starts_with(trim($line), '|')) {
                $errors[] = 'Acceptance map must be one uninterrupted table; put notes in a separate section.';
            }
            $rows[] = $this->cells(trim($line));
        }
        if (count($rows) < 3 || $rows[0] !== ['Criterion', 'Boundary', 'Focused proof']
            || count($rows[1]) !== 3
            || count(array_filter($rows[1], static fn (string $cell): bool => preg_match('/^:?-{3,}:?$/', $cell) === 1)) !== 3) {
            $errors[] = 'Acceptance map needs the template table header, separator, and at least one row.';
        }
        foreach (array_slice($rows, 2) as $index => $row) {
            if (count($row) !== 3 || count(array_filter($row, fn (string $cell): bool => $cell !== '' && ! $this->placeholder($cell))) !== 3) {
                $errors[] = 'Acceptance row '.($index + 1).' needs three nonempty cells.';
            }
        }

        return $errors;
    }

    private function placeholder(string $line): bool
    {
        return preg_match('/\{\{[^}]+\}\}|^(?:[-*]|\d+\.|TODO|TBD|TBC|\.{3})(?:\s*)$/i', trim($line)) === 1;
    }

    /** @return list<string> */
    private function cells(string $line): array
    {
        $cells = [];
        $cell = '';
        $ticks = 0;
        $length = strlen($line);
        for ($i = 1; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $cell .= $char.$line[++$i];
            } elseif ($char === '`') {
                $run = strspn($line, '`', $i);
                $ticks = $ticks === 0 ? $run : ($ticks === $run ? 0 : $ticks);
                $cell .= str_repeat('`', $run);
                $i += $run - 1;
            } elseif ($char === '|' && $ticks === 0) {
                $cells[] = trim($cell);
                $cell = '';
            } else {
                $cell .= $char;
            }
        }
        if ($cell !== '' || ! str_ends_with($line, '|') || $ticks !== 0) {
            $cells[] = trim($cell);
        }

        return $cells;
    }
}
