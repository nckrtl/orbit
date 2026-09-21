<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Tokens and line diff observed on one T3 thread snapshot.
 *
 * Tokens prefer cumulative `totalProcessedTokens` over the current-window
 * `usedTokens`. Line diff sums checkpoint file additions and deletions for
 * that thread only.
 */
final readonly class T3ThreadMetrics
{
    public function __construct(
        public int $tokens,
        public int $lineDiff,
        public ?int $linesAdded = null,
        public ?int $linesDeleted = null,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromSnapshot(array $snapshot): self
    {
        $thread = $snapshot['thread'] ?? $snapshot;

        if (! is_array($thread)) {
            return new self(0, 0);
        }

        /** @var array<string, mixed> $thread */
        return new self(
            tokens: self::tokens($thread),
            lineDiff: self::lineCount($thread, 'additions') + self::lineCount($thread, 'deletions'),
            linesAdded: is_array($thread['checkpoints'] ?? null) ? self::lineCount($thread, 'additions') : null,
            linesDeleted: is_array($thread['checkpoints'] ?? null) ? self::lineCount($thread, 'deletions') : null,
        );
    }

    /**
     * @param  array<string, mixed>  $thread
     */
    private static function tokens(array $thread): int
    {
        $tokens = 0;
        self::walk($thread, function (array $node) use (&$tokens): void {
            if (! array_key_exists('usedTokens', $node)) {
                return;
            }

            $used = self::nonNegativeInt($node['usedTokens']);
            $processed = self::nonNegativeInt($node['totalProcessedTokens'] ?? null);
            $value = $processed !== null && $processed > 0
                ? $processed
                : ($used ?? 0);
            $tokens = max($tokens, $value);
        });

        return $tokens;
    }

    /**
     * @param  array<string, mixed>  $thread
     */
    private static function lineCount(array $thread, string $kind): int
    {
        $checkpoints = $thread['checkpoints'] ?? null;

        if (! is_array($checkpoints)) {
            return 0;
        }

        $total = 0;

        foreach ($checkpoints as $checkpoint) {
            if (! is_array($checkpoint)) {
                continue;
            }

            $files = $checkpoint['files'] ?? null;

            if (! is_array($files)) {
                continue;
            }

            foreach ($files as $file) {
                if (! is_array($file)) {
                    continue;
                }

                $total += self::nonNegativeInt($file[$kind] ?? null) ?? 0;
            }
        }

        return $total;
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @param  callable(array<string, mixed>): void  $visitor
     */
    private static function walk(array $node, callable $visitor): void
    {
        if (self::isMap($node)) {
            /** @var array<string, mixed> $node */
            $visitor($node);
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                self::walk($child, $visitor);
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $node
     */
    private static function isMap(array $node): bool
    {
        foreach (array_keys($node) as $key) {
            if (! is_string($key)) {
                return false;
            }
        }

        return $node !== [];
    }

    private static function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_float($value) && $value >= 0.0 && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }
}
