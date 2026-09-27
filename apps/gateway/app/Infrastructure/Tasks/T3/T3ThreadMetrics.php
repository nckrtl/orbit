<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\T3;

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
    /**
     * @param  array<string, mixed>|null  $checkpoint
     */
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
        public ?array $checkpoint = null,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>|null  $checkpoint
     */
    public static function fromSnapshot(array $snapshot, ?array $checkpoint = null, ?int $sequence = null): self
    {
        $thread = $snapshot['thread'] ?? $snapshot;

        if (! is_array($thread)) {
            return new self(null, null);
        }

        /** @var array<string, mixed> $thread */
        $sequence ??= self::sequence($snapshot);
        $split = $checkpoint === null
            ? self::split($thread, $sequence)
            : self::accumulate($thread, $checkpoint, $sequence);

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
            checkpoint: $split['checkpoint'],
        );
    }

    /**
     * Establish the stream baseline without treating its latest update as a call.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>|null  $checkpoint
     */
    public static function baseline(array $snapshot, ?array $checkpoint, ?int $sequence): self
    {
        $thread = $snapshot['thread'] ?? $snapshot;
        if (! is_array($thread)) {
            return new self(null, null, checkpoint: $checkpoint);
        }

        $fresh = $checkpoint === null;
        $checkpoint ??= [];
        $observed = self::checkpointInt($checkpoint, 't3_observed_total_processed_tokens');
        $partial = ($checkpoint['t3_metrics_partial'] ?? false) === true;
        $totals = array_values(array_filter(array_map(
            static fn (array $payload): ?int => self::nonNegativeInt($payload['totalProcessedTokens'] ?? null),
            self::contextWindowPayloads($thread),
        ), static fn (?int $total): bool => $total !== null));
        if ($totals === []) {
            $partial = true;
        } else {
            $baseline = max($totals);
            if (($fresh || $observed === null) && $baseline > 0) {
                $partial = true;
            }
            if ($observed !== null && $baseline < $observed) {
                $partial = true;
            } elseif ($observed !== null && $baseline > $observed) {
                $partial = true;
                $observed = $baseline;
            } else {
                $observed ??= $baseline;
            }
        }

        $checkpoint = [
            't3_input_tokens' => self::checkpointInt($checkpoint, 't3_input_tokens') ?? 0,
            't3_cached_input_tokens' => self::checkpointInt($checkpoint, 't3_cached_input_tokens') ?? 0,
            't3_output_tokens' => self::checkpointInt($checkpoint, 't3_output_tokens') ?? 0,
            't3_model_calls' => self::checkpointInt($checkpoint, 't3_model_calls') ?? 0,
            't3_peak_context_tokens' => self::checkpointInt($checkpoint, 't3_peak_context_tokens'),
            't3_counted_total_processed_tokens' => self::checkpointInt($checkpoint, 't3_counted_total_processed_tokens'),
            't3_observed_total_processed_tokens' => $observed,
            't3_metrics_partial' => $partial,
            't3_metrics_initialized' => true,
            't3_event_sequence' => self::checkpointInt($checkpoint, 't3_event_sequence'),
        ];
        if ($sequence !== null) {
            $checkpoint['t3_event_sequence'] = max($checkpoint['t3_event_sequence'] ?? $sequence, $sequence);
        }

        return self::fromPersisted($snapshot, $checkpoint);
    }

    /**
     * Apply one ordered T3 event. Non-usage events still advance the cursor.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>|null  $checkpoint
     */
    public static function fromEvent(array $event, ?array $checkpoint, int $sequence): self
    {
        $payload = $event['payload'] ?? [];
        $activity = is_array($payload) && is_array($payload['activity'] ?? null) ? $payload['activity'] : $payload;
        $snapshot = ['thread' => ['activities' => is_array($activity) ? [$activity] : []]];
        if (! is_array($activity) || ($activity['kind'] ?? null) !== 'context-window.updated') {
            $next = $checkpoint ?? [];
            $next['t3_metrics_initialized'] = true;
            $next['t3_event_sequence'] = max(self::checkpointInt($next, 't3_event_sequence') ?? $sequence, $sequence);

            return self::fromPersisted($snapshot, $next);
        }

        return self::fromSnapshot($snapshot, $checkpoint ?? [], $sequence);
    }

    /**
     * Read the durable checkpoint without deriving a new call from a polling
     * snapshot. Polling may still refresh state, tokens, and line counts.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>|null  $checkpoint
     */
    public static function fromPersisted(array $snapshot, ?array $checkpoint): self
    {
        $thread = $snapshot['thread'] ?? $snapshot;
        if (! is_array($thread)) {
            return new self(null, null, checkpoint: $checkpoint);
        }

        $calls = self::checkpointInt($checkpoint ?? [], 't3_model_calls') ?? 0;
        $partial = ($checkpoint['t3_metrics_partial'] ?? false) === true;
        $tokens = self::tokens($thread);
        $observedTotal = self::checkpointInt($checkpoint ?? [], 't3_observed_total_processed_tokens');
        if ($observedTotal !== null) {
            $tokens = max($tokens ?? 0, $observedTotal);
        }
        $split = [
            'inputTokens' => $calls > 0 && ! $partial ? self::checkpointInt($checkpoint ?? [], 't3_input_tokens') : null,
            'cachedInputTokens' => $calls > 0 && ! $partial ? self::checkpointInt($checkpoint ?? [], 't3_cached_input_tokens') : null,
            'outputTokens' => $calls > 0 && ! $partial ? self::checkpointInt($checkpoint ?? [], 't3_output_tokens') : null,
            'modelCalls' => $calls > 0 && ! $partial ? $calls : null,
            'peakContextTokens' => $calls > 0 && ! $partial ? self::checkpointInt($checkpoint ?? [], 't3_peak_context_tokens') : null,
        ];

        return new self(
            tokens: $tokens,
            lineDiff: is_array($thread['checkpoints'] ?? null) ? self::lineCount($thread, 'additions') + self::lineCount($thread, 'deletions') : null,
            linesAdded: is_array($thread['checkpoints'] ?? null) ? self::lineCount($thread, 'additions') : null,
            linesDeleted: is_array($thread['checkpoints'] ?? null) ? self::lineCount($thread, 'deletions') : null,
            inputTokens: $split['inputTokens'],
            cachedInputTokens: $split['cachedInputTokens'],
            outputTokens: $split['outputTokens'],
            modelCalls: $split['modelCalls'],
            peakContextTokens: $split['peakContextTokens'],
            checkpoint: $checkpoint,
        );
    }

    /**
     * Counted calls from `context-window.updated` activities, in snapshot order.
     *
     * This is retained for snapshots that have not yet got a durable
     * checkpoint. Once a checkpoint exists, accumulate() is used instead so a
     * bounded snapshot can contribute its latest call without recounting it.
     *
     * @param  array<string, mixed>  $thread
     * @return array{inputTokens: ?int, cachedInputTokens: ?int, outputTokens: ?int, modelCalls: ?int, peakContextTokens: ?int, checkpoint: array<string, mixed>}
     */
    private static function split(array $thread, ?int $sequence): array
    {
        $inputTokens = 0;
        $cachedInputTokens = 0;
        $outputTokens = 0;
        $calls = 0;
        $peak = null;
        $countedTotal = null;
        $observedTotal = null;
        $partial = false;
        $previous = null;

        foreach (self::contextWindowPayloads($thread) as $payload) {
            $total = self::nonNegativeInt($payload['totalProcessedTokens'] ?? null);
            if ($total !== null && $observedTotal !== null && $total < $observedTotal) {
                $partial = true;

                continue;
            }

            $input = self::nonNegativeInt($payload['inputTokens'] ?? null);
            $cached = self::nonNegativeInt($payload['cachedInputTokens'] ?? null);
            $output = self::nonNegativeInt($payload['outputTokens'] ?? null);
            $advances = $total !== null && ($observedTotal === null || $total > $observedTotal);
            if ($input === null || $cached === null || $output === null) {
                if ($advances) {
                    $partial = true;
                }
                if ($total !== null) {
                    $observedTotal = max($observedTotal ?? 0, $total);
                }

                continue;
            }

            $triple = [$input, $cached, $output];
            $counted = $advances || ($observedTotal === null && $triple !== $previous);
            if ($counted && $cached > $input) {
                $partial = true;

                if ($total !== null) {
                    $observedTotal = max($observedTotal ?? 0, $total);
                }

                continue;
            }
            if ($counted) {
                $inputTokens += $input - $cached;
                $cachedInputTokens += $cached;
                $outputTokens += $output;
                $calls++;
                $peak = max($peak ?? 0, $input);
                $countedTotal = $total ?? $countedTotal;
                $previous = $triple;
            }
            if ($total !== null) {
                $observedTotal = max($observedTotal ?? 0, $total);
            }
        }

        $result = self::result($inputTokens, $cachedInputTokens, $outputTokens, $calls, $peak, $countedTotal, $observedTotal, $partial);
        if ($sequence !== null) {
            $result['checkpoint']['t3_event_sequence'] = $sequence;
        }

        return $result;
    }

    /**
     * Apply only cumulative totals that are newer than the durable observed
     * watermark. This makes a repeated bounded snapshot and a Gateway restart
     * idempotent while retaining sums from earlier observations.
     *
     * @param  array<string, mixed>  $thread
     * @param  array<string, mixed>  $checkpoint
     * @return array{inputTokens: ?int, cachedInputTokens: ?int, outputTokens: ?int, modelCalls: ?int, peakContextTokens: ?int, checkpoint: array<string, mixed>}
     */
    private static function accumulate(array $thread, array $checkpoint, ?int $sequence): array
    {
        $inputTokens = self::checkpointInt($checkpoint, 't3_input_tokens') ?? 0;
        $cachedInputTokens = self::checkpointInt($checkpoint, 't3_cached_input_tokens') ?? 0;
        $outputTokens = self::checkpointInt($checkpoint, 't3_output_tokens') ?? 0;
        $calls = self::checkpointInt($checkpoint, 't3_model_calls') ?? 0;
        $peak = self::checkpointInt($checkpoint, 't3_peak_context_tokens');
        $countedTotal = self::checkpointInt($checkpoint, 't3_counted_total_processed_tokens');
        $observedTotal = self::checkpointInt($checkpoint, 't3_observed_total_processed_tokens');
        $partial = ($checkpoint['t3_metrics_partial'] ?? false) === true;
        $sawLowerTotal = false;
        $sawAdvancingTotal = false;

        foreach (self::contextWindowPayloads($thread) as $payload) {
            $total = self::nonNegativeInt($payload['totalProcessedTokens'] ?? null);
            if ($total === null) {
                $partial = true;

                continue;
            }
            if ($observedTotal !== null && $total < $observedTotal) {
                $sawLowerTotal = true;

                continue;
            }
            if ($observedTotal !== null && $total === $observedTotal) {
                continue;
            }

            $sawAdvancingTotal = true;
            $observedTotal = $total;
            $input = self::nonNegativeInt($payload['inputTokens'] ?? null);
            $cached = self::nonNegativeInt($payload['cachedInputTokens'] ?? null);
            $output = self::nonNegativeInt($payload['outputTokens'] ?? null);
            if ($input === null || $cached === null || $output === null || $cached > $input) {
                $partial = true;

                continue;
            }

            $inputTokens += $input - $cached;
            $cachedInputTokens += $cached;
            $outputTokens += $output;
            $calls++;
            $peak = max($peak ?? 0, $input);
            $countedTotal = $total;
        }

        $partial = $partial || ($sawLowerTotal && ! $sawAdvancingTotal);
        $result = self::result($inputTokens, $cachedInputTokens, $outputTokens, $calls, $peak, $countedTotal, $observedTotal, $partial);
        if ($sequence !== null) {
            $result['checkpoint']['t3_event_sequence'] = max(self::checkpointInt($checkpoint, 't3_event_sequence') ?? $sequence, $sequence);
        }

        return $result;
    }

    /**
     * @return array{inputTokens: ?int, cachedInputTokens: ?int, outputTokens: ?int, modelCalls: ?int, peakContextTokens: ?int, checkpoint: array<string, mixed>}
     */
    private static function result(int $input, int $cached, int $output, int $calls, ?int $peak, ?int $countedTotal, ?int $observedTotal, bool $partial): array
    {
        return [
            'inputTokens' => $calls > 0 && ! $partial ? $input : null,
            'cachedInputTokens' => $calls > 0 && ! $partial ? $cached : null,
            'outputTokens' => $calls > 0 && ! $partial ? $output : null,
            'modelCalls' => $calls > 0 && ! $partial ? $calls : null,
            'peakContextTokens' => $calls > 0 && ! $partial ? $peak : null,
            'checkpoint' => [
                't3_input_tokens' => $input,
                't3_cached_input_tokens' => $cached,
                't3_output_tokens' => $output,
                't3_model_calls' => $calls,
                't3_peak_context_tokens' => $peak,
                't3_counted_total_processed_tokens' => $countedTotal,
                't3_observed_total_processed_tokens' => $observedTotal,
                't3_metrics_partial' => $partial,
                't3_metrics_initialized' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function sequence(array $snapshot): ?int
    {
        return self::nonNegativeInt($snapshot['snapshotSequence'] ?? $snapshot['sequence'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     */
    private static function checkpointInt(array $checkpoint, string $key): ?int
    {
        return self::nonNegativeInt($checkpoint[$key] ?? null);
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

    /** @param array<string, mixed> $thread */
    private static function tokens(array $thread): ?int
    {
        $tokens = null;
        self::walk($thread, function (array $node) use (&$tokens): void {
            if (! array_key_exists('usedTokens', $node) && ! array_key_exists('totalProcessedTokens', $node)) {
                return;
            }

            $used = self::nonNegativeInt($node['usedTokens'] ?? null);
            $processed = self::nonNegativeInt($node['totalProcessedTokens'] ?? null);
            $value = $processed !== null && $processed > 0 ? $processed : $used;
            if ($value !== null) {
                $tokens = max($tokens ?? 0, $value);
            }
        });

        return $tokens;
    }

    /** @param array<string, mixed> $thread */
    private static function lineCount(array $thread, string $kind): int
    {
        $checkpoints = $thread['checkpoints'] ?? null;
        if (! is_array($checkpoints)) {
            return 0;
        }

        $total = 0;
        foreach ($checkpoints as $checkpoint) {
            if (! is_array($checkpoint) || ! is_array($checkpoint['files'] ?? null)) {
                continue;
            }
            foreach ($checkpoint['files'] as $file) {
                if (is_array($file)) {
                    $total += self::nonNegativeInt($file[$kind] ?? null) ?? 0;
                }
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

    /** @param array<int|string, mixed> $node */
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
