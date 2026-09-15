<?php

declare(strict_types=1);

namespace App\Support\Console;

use InvalidArgumentException;
use LengthException;

final class TerminalText
{
    public static function safe(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');

        return preg_replace_callback(
            '/[\p{Cc}\x{061c}\x{200e}\x{200f}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u',
            static fn (array $match): string => match ($match[0]) {
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                default => sprintf('\\u{%04X}', mb_ord($match[0], 'UTF-8')),
            },
            $text,
        ) ?? '';
    }

    /** @param scalar|null|list<scalar|null> $value */
    public static function displayValue(string|int|float|bool|array|null $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        if (is_array($value)) {
            return implode(', ', array_map(self::displayValue(...), $value));
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return self::safe((string) $value);
    }

    public static function plain(string $text): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $text) ?? '';
    }

    public static function width(string $text): int
    {
        return array_sum(array_map(self::graphemeWidth(...), self::graphemes(self::plain($text))));
    }

    public static function minimumWidth(string $text): int
    {
        return max([1, ...array_map(self::graphemeWidth(...), self::graphemes(self::plain($text)))]);
    }

    /**
     * Wrap already-safe plain text without dropping whitespace or splitting a grapheme.
     *
     * @return non-empty-list<string>
     */
    public static function wrap(string $text, int $columns): array
    {
        if ($columns < 1) {
            throw new InvalidArgumentException('Text width must be positive.');
        }

        $lines = [];
        $line = '';
        $width = 0;

        foreach (self::graphemes($text) as $grapheme) {
            $size = self::graphemeWidth($grapheme);

            if ($size > $columns) {
                throw new LengthException("Text requires at least {$size} terminal columns.");
            }

            if ($width + $size > $columns) {
                $lines[] = $line;
                $line = '';
                $width = 0;
            }

            $line .= $grapheme;
            $width += $size;
        }

        $lines[] = $line;

        return $lines;
    }

    public static function pad(string $text, int $columns): string
    {
        return $text.str_repeat(' ', max(0, $columns - self::width($text)));
    }

    public static function style(string $text, string $style, bool $decorated): string
    {
        if (! $decorated || $text === '') {
            return $text;
        }

        $code = match ($style) {
            'dim' => '2',
            'red' => '31',
            'green' => '32',
            'cyan' => '36',
            'orange' => '38;5;208',
            'inverse' => '7',
            default => throw new InvalidArgumentException('Unknown terminal style.'),
        };

        return "\033[{$code}m{$text}\033[0m";
    }

    /** @return list<string> */
    private static function graphemes(string $text): array
    {
        preg_match_all('/\X/u', $text, $matches);

        return $matches[0];
    }

    private static function graphemeWidth(string $grapheme): int
    {
        $base = preg_replace('/[\p{M}\x{200d}]/u', '', $grapheme) ?? '';

        return mb_strwidth($base, 'UTF-8');
    }
}
