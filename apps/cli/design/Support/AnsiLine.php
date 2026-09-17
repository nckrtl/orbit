<?php

declare(strict_types=1);

namespace Design\Support;

use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;

/**
 * Design sketch: turns one line of SGR-coloured terminal text, as Laravel Prompts renders
 * it, into a php-tui Line of styled spans. Only the codes the Prompts themes emit are read.
 */
final class AnsiLine
{
    public static function parse(string $text): Line
    {
        $spans = [];
        $style = Style::default();
        $reverse = false;
        $parts = preg_split('/(\e\[[0-9;]*m)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($parts as $part) {
            if (preg_match('/^\e\[([0-9;]*)m$/', $part, $match) === 1) {
                foreach (explode(';', $match[1] === '' ? '0' : $match[1]) as $code) {
                    [$style, $reverse] = self::apply($style, $reverse, (int) $code);
                }

                continue;
            }
            $spans[] = Span::styled($part, $reverse ? $style->addModifier(Modifier::REVERSED) : $style);
        }

        return Line::fromSpans(...$spans);
    }

    /** @return array{Style, bool} */
    private static function apply(Style $style, bool $reverse, int $code): array
    {
        $colours = [AnsiColor::Black, AnsiColor::Red, AnsiColor::Green, AnsiColor::Yellow, AnsiColor::Blue, AnsiColor::Magenta, AnsiColor::Cyan, AnsiColor::Gray];
        $bright = [AnsiColor::DarkGray, AnsiColor::LightRed, AnsiColor::LightGreen, AnsiColor::LightYellow, AnsiColor::LightBlue, AnsiColor::LightMagenta, AnsiColor::LightCyan, AnsiColor::White];

        return match (true) {
            $code === 0 => [Style::default(), false],
            $code === 1 => [$style->addModifier(Modifier::BOLD), $reverse],
            $code === 2 => [$style->addModifier(Modifier::DIM), $reverse],
            $code === 4 => [$style->addModifier(Modifier::UNDERLINED), $reverse],
            $code === 7 => [$style, true],
            $code === 22 => [$style->removeModifier(Modifier::BOLD)->removeModifier(Modifier::DIM), $reverse],
            $code === 24 => [$style->removeModifier(Modifier::UNDERLINED), $reverse],
            $code === 27 => [$style, false],
            $code >= 30 && $code <= 37 => [$style->fg($colours[$code - 30]), $reverse],
            $code === 39 => [$style->fg(AnsiColor::Reset), $reverse],
            $code >= 40 && $code <= 47 => [$style->bg($colours[$code - 40]), $reverse],
            $code === 49 => [$style->bg(AnsiColor::Reset), $reverse],
            $code >= 90 && $code <= 97 => [$style->fg($bright[$code - 90]), $reverse],
            $code >= 100 && $code <= 107 => [$style->bg($bright[$code - 100]), $reverse],
            default => [$style, $reverse],
        };
    }
}
