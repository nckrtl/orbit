<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\T3;

use App\Support\ValidatedData;

/**
 * Tokens and line diff observed on one T3 thread snapshot.
 *
 * Tokens prefer cumulative `totalProcessedTokens` over the current-window
 * `usedTokens`. The five split fields come from counted `context-window.updated`
 * activities. Line diff sums checkpoint file additions and deletions for
 * that thread only.
 */
final readonly class T3ThreadMetrics
{
    public function __construct(
        public ?int $tokens,
        public ?int $lineDiff,
        public ?int $linesAdded = null,
        public ?int $linesDeleted = null,
        public ?int $inputTokens = null,
        public ?int $cachedInputTokens = null,
        public ?int $outputTokens = null,
        public ?int $modelCalls = null,
        public ?int $peakContextTokens = null,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromSnapshot(array $snapshot): self
    {
        $thread = $snapshot['thread'] ?? $snapshot;

        if (! is_array($thread)) {
            return new self(null, null);
        }
        $thread = ValidatedData::object($thread);

        $split = self::split($thread);

        return new self(
            tokens: self::tokens($thread),
            lineDiff: is_array($thread['checkpoints'] ?? null) ? self::lineCount($thread, 'additions') + self::lineCount($thread, 'deletions') : null,
            linesAdded: is_array($thread['checkpoints'] ?? null) ? self::lineCount($thread, 'additions') : null,
            linesDeleted: is_array($thread['checkpoints'] ?? null) ? self::lineCount($thread, 'deletions') : null,
            inputTokens: $split['inputTokens'],
            cachedInputTokens: $split['cachedInputTokens'],
            outputTokens: $split['outputTokens'],
            modelCalls: $split['modelCalls'],
            peakContextTokens: $split['peakContextTokens'],
        );
    }

    /**
     * Counted calls from `context-window.updated` activities, in snapshot order.
     *
     * A repeated update is not another call. A total that falls, or an advancing
     * total without the three integers, publishes no split. `last*` and
     * `reasoningOutputTokens` are not part of the sum.
     *
     * @param  array<string, mixed>  $thread
     * @return array{inputTokens: ?int, cachedInputTokens: ?int, outputTokens: ?int, modelCalls: ?int, peakContextTokens: ?int}
     */
    private static function split(array $thread): array
    {
        $empty = ['inputTokens' => null, 'cachedInputTokens' => null, 'outputTokens' => null, 'modelCalls' => null, 'peakContextTokens' => null];
        $earlierTotal = null;
        $previous = null;
        $inputTokens = 0;
        $cachedInputTokens = 0;
        $outputTokens = 0;
        $calls = 0;
        $peak = null;

        foreach (self::contextWindowPayloads($thread) as $payload) {
            $total = self::nonNegativeInt($payload['totalProcessedTokens'] ?? null);
            if ($total !== null && $earlierTotal !== null && $total < $earlierTotal) {
                return $empty;
            }

            $input = self::nonNegativeInt($payload['inputTokens'] ?? null);
            $cached = self::nonNegativeInt($payload['cachedInputTokens'] ?? null);
            $output = self::nonNegativeInt($payload['outputTokens'] ?? null);
            $advances = $total !== null && ($earlierTotal === null || $total > $earlierTotal);
            if ($input === null || $cached === null || $output === null) {
                if ($advances) {
                    return $empty;
                }
                if ($total !== null) {
                    $earlierTotal = max($earlierTotal ?? 0, $total);
                }

                continue;
            }

            $triple = [$input, $cached, $output];
            $counted = $advances || ($earlierTotal === null && $triple !== $previous);
            if ($counted && $cached > $input) {
                return $empty;
            }
            if ($counted) {
                $inputTokens += $input - $cached;
                $cachedInputTokens += $cached;
                $outputTokens += $output;
                $calls++;
                $peak = max($peak ?? 0, $input);
                $previous = $triple;
            }
            if ($total !== null) {
                $earlierTotal = max($earlierTotal ?? 0, $total);
            }
        }

        if ($calls === 0) {
            return $empty;
        }

        return ['inputTokens' => $inputTokens, 'cachedInputTokens' => $cachedInputTokens, 'outputTokens' => $outputTokens, 'modelCalls' => $calls, 'peakContextTokens' => $peak];
    }

    /**
     * @param  array<string, mixed>  $thread
     * @return list<array<string, mixed>>
     */
    private static function contextWindowPayloads(array $thread): array
    {
        $payloads = [];
        self::walk($thread, function (array $node) use (&$payloads): void {
            if (($node['kind'] ?? null) !== 'context-window.updated') {
                return;
            }
            $payload = $node['payload'] ?? null;
            $payloads[] = is_array($payload) && ! array_is_list($payload) ? $payload : [];
        });

        return $payloads;
    }

    /**
     * @param  array<string, mixed>  $thread
     */
    private static function tokens(array $thread): ?int
    {
        $tokens = null;
        self::walk($thread, function (array $node) use (&$tokens): void {
            if (! array_key_exists('usedTokens', $node) && ! array_key_exists('totalProcessedTokens', $node)) {
                return;
            }

            $used = self::nonNegativeInt($node['usedTokens'] ?? null);
            $processed = self::nonNegativeInt($node['totalProcessedTokens'] ?? null);
            $value = $processed !== null && $processed > 0
                ? $processed
                : $used;
            if ($value !== null) {
                $tokens = max($tokens ?? 0, $value);
            }
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
            $object = [];
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    $object[$key] = $value;
                }
            }
            $visitor($object);
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
