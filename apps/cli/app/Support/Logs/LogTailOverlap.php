<?php

declare(strict_types=1);

namespace App\Support\Logs;

/**
 * Finds where the lines printed last end inside a newly read log tail, so a follow prints each
 * line once when it polls a one-shot read or reopens a stream. It keeps the last five printed
 * lines as context. The newest place where the whole context appears wins; failing that, a tail
 * that starts with at least two of the context's last lines continues after them.
 *
 * The live stream cuts a line longer than 8 KiB and ends it with ` [truncated]`, while a one-shot
 * read returns it whole. Two lines are the same when they are equal, or when one is such a cut line
 * and the other starts with its text.
 */
final class LogTailOverlap
{
    private const int CONTEXT = 5;

    private const string TRUNCATED = ' [truncated]';

    /** The shortest text a cut line keeps. The Node agent keeps about 8 KiB; redaction can shorten it. */
    private const int CUT_TEXT_BYTES = 4096;

    /** @var list<string> */
    private array $recent = [];

    /** @param  list<string>  $lines */
    public function remember(array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $this->recent = array_slice([...$this->recent, ...$lines], -self::CONTEXT);
    }

    /**
     * Start the context again from the newest of $lines, which follow each other in the log, after a
     * gap: the lines printed before the gap no longer lead up to the new ones.
     *
     * @param  list<string>  $lines
     */
    public function restart(array $lines): void
    {
        $this->recent = array_slice($lines, -self::CONTEXT);
    }

    public function hasContext(): bool
    {
        return $this->recent !== [];
    }

    /**
     * The lines of $tail after the remembered ones, or null when the tail does not contain them.
     * Without context every line is new.
     *
     * @param  list<string>  $tail
     * @return list<string>|null
     */
    public function find(array $tail): ?array
    {
        $context = count($this->recent);

        if ($context === 0) {
            return $tail;
        }

        for ($start = count($tail) - $context; $start >= 0; $start--) {
            if (self::sameLines(array_slice($tail, $start, $context), $this->recent)) {
                return array_slice($tail, $start + $context);
            }
        }

        for ($length = min($context - 1, count($tail)); $length >= 2; $length--) {
            if (self::sameLines(array_slice($tail, 0, $length), array_slice($this->recent, -$length))) {
                return array_slice($tail, $length);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $first
     * @param  list<string>  $second
     */
    private static function sameLines(array $first, array $second): bool
    {
        if (count($first) !== count($second)) {
            return false;
        }

        return array_all($first, fn ($line, $index) => self::sameLine($line, $second[$index]));
    }

    public static function sameLine(string $first, string $second): bool
    {
        return $first === $second || self::cutFrom($first, $second) || self::cutFrom($second, $first);
    }

    /** Whether $cut is $whole cut short by the live stream. */
    private static function cutFrom(string $cut, string $whole): bool
    {
        if (! str_ends_with($cut, self::TRUNCATED)) {
            return false;
        }

        $text = substr($cut, 0, -strlen(self::TRUNCATED));

        return strlen($text) >= self::CUT_TEXT_BYTES && strlen($whole) > strlen($text) && str_starts_with($whole, $text);
    }
}
