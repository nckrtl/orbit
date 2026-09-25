<?php

declare(strict_types=1);

namespace App\Support\Logs;

/**
 * Finds where the lines printed last end inside a newly read log tail, so a follow prints each
 * line once when it polls a one-shot read or reopens a stream. It keeps the last five printed
 * lines as context. The newest place where the whole context appears wins; failing that, a tail
 * that starts with at least two of the context's last lines continues after them.
 */
final class LogTailOverlap
{
    private const int CONTEXT = 5;

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

    public function hasContext(): bool
    {
        return $this->recent !== [];
    }

    /**
     * The lines of $tail after the remembered ones, or every line when no overlap is found.
     *
     * @param  list<string>  $tail
     * @return list<string>
     */
    public function after(array $tail): array
    {
        return $this->find($tail) ?? $tail;
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
            if (array_slice($tail, $start, $context) === $this->recent) {
                return array_slice($tail, $start + $context);
            }
        }

        for ($length = min($context - 1, count($tail)); $length >= 2; $length--) {
            if (array_slice($tail, 0, $length) === array_slice($this->recent, -$length)) {
                return array_slice($tail, $length);
            }
        }

        return null;
    }
}
