<?php

declare(strict_types=1);

use App\Infrastructure\Tasks\T3\T3ThreadMetrics;

it('prefers cumulative processed tokens over the current window', function (): void {
    $metrics = T3ThreadMetrics::fromSnapshot([
        'snapshotSequence' => 4,
        'thread' => [
            'id' => 'thread-1',
            'activities' => [
                [
                    'kind' => 'token-usage',
                    'payload' => [
                        'usage' => [
                            'usedTokens' => 1200,
                            'totalProcessedTokens' => 8400,
                        ],
                    ],
                ],
            ],
            'checkpoints' => [],
        ],
    ]);

    expect($metrics->tokens)->toBe(8400)
        ->and($metrics->lineDiff)->toBe(0);
});

it('falls back to usedTokens when the snapshot has no processed total', function (): void {
    $metrics = T3ThreadMetrics::fromSnapshot([
        'thread' => [
            'activities' => [
                [
                    'payload' => [
                        'usage' => ['usedTokens' => 640],
                    ],
                ],
            ],
        ],
    ]);

    expect($metrics->tokens)->toBe(640);
});

it('keeps the largest session total across usage snapshots', function (): void {
    $metrics = T3ThreadMetrics::fromSnapshot([
        'thread' => [
            'activities' => [
                ['payload' => ['usage' => ['usedTokens' => 10, 'totalProcessedTokens' => 100]]],
                ['payload' => ['usage' => ['usedTokens' => 20, 'totalProcessedTokens' => 250]]],
            ],
        ],
    ]);

    expect($metrics->tokens)->toBe(250);
});

it('sums checkpoint file additions and deletions for the thread', function (): void {
    $metrics = T3ThreadMetrics::fromSnapshot([
        'thread' => [
            'checkpoints' => [
                [
                    'files' => [
                        ['path' => 'app/Models/Task.php', 'kind' => 'modified', 'additions' => 10, 'deletions' => 2],
                        ['path' => 'logo.png', 'kind' => 'added', 'additions' => 0, 'deletions' => 0],
                    ],
                ],
                [
                    'files' => [
                        ['path' => 'docs/reference/tasks.md', 'kind' => 'modified', 'additions' => 3, 'deletions' => 1],
                    ],
                ],
            ],
        ],
    ]);

    expect($metrics->linesAdded)->toBe(13)
        ->and($metrics->linesDeleted)->toBe(3)
        ->and($metrics->lineDiff)->toBe(16)
        ->and($metrics->tokens)->toBeNull();
});

it('leaves metrics unknown for an empty or malformed snapshot', function (): void {
    expect(T3ThreadMetrics::fromSnapshot([])->tokens)->toBeNull()
        ->and(T3ThreadMetrics::fromSnapshot(['thread' => 'nope'])->lineDiff)->toBeNull()
        ->and(T3ThreadMetrics::fromSnapshot([])->inputTokens)->toBeNull()
        ->and(T3ThreadMetrics::fromSnapshot([])->modelCalls)->toBeNull();
});

it('counts every model call from the event stream between observations', function (): void {
    $baseline = T3ThreadMetrics::baseline(['thread' => ['activities' => [[
        'kind' => 'context-window.updated',
        'payload' => ['totalProcessedTokens' => 0, 'inputTokens' => 0, 'cachedInputTokens' => 0, 'outputTokens' => 0],
    ]]]], null, 10);

    $metrics = $baseline;
    foreach ([
        [11, 250, 110, 30, 20],
        [12, 400, 140, 40, 30],
        [13, 550, 160, 50, 40],
    ] as [$sequence, $total, $input, $cached, $output]) {
        $metrics = T3ThreadMetrics::fromEvent([
            'type' => 'thread.activity-appended',
            'payload' => ['activity' => [
                'kind' => 'context-window.updated',
                'payload' => [
                    'totalProcessedTokens' => $total,
                    'inputTokens' => $input,
                    'cachedInputTokens' => $cached,
                    'outputTokens' => $output,
                ],
            ]],
        ], $metrics->checkpoint, $sequence);
    }

    $observed = T3ThreadMetrics::fromPersisted(['thread' => ['activities' => [[
        'kind' => 'context-window.updated',
        'payload' => ['totalProcessedTokens' => 550, 'inputTokens' => 160, 'cachedInputTokens' => 50, 'outputTokens' => 40],
    ]]]], $metrics->checkpoint);

    expect($baseline->modelCalls)->toBeNull()
        ->and($observed->tokens)->toBe(550)
        ->and($observed->modelCalls)->toBe(3)
        ->and($observed->inputTokens)->toBe(290)
        ->and($observed->cachedInputTokens)->toBe(120)
        ->and($observed->outputTokens)->toBe(90)
        ->and($observed->checkpoint['t3_event_sequence'])->toBe(13);
});

it('marks a fresh nonzero baseline partial without counting it', function (): void {
    $metrics = T3ThreadMetrics::baseline(['thread' => ['activities' => [[
        'kind' => 'context-window.updated',
        'payload' => ['totalProcessedTokens' => 100, 'inputTokens' => 90, 'cachedInputTokens' => 20, 'outputTokens' => 10],
    ]]]], null, 10);

    expect($metrics->modelCalls)->toBeNull()
        ->and($metrics->checkpoint['t3_observed_total_processed_tokens'])->toBe(100)
        ->and($metrics->checkpoint['t3_metrics_partial'])->toBeTrue();
});

it('marks an unknown nonzero baseline partial after a non-usage event', function (): void {
    $checkpoint = T3ThreadMetrics::fromEvent([
        'type' => 'thread.session-set',
        'payload' => [],
    ], null, 10)->checkpoint;
    $metrics = T3ThreadMetrics::baseline(['thread' => ['activities' => [[
        'kind' => 'context-window.updated',
        'payload' => ['totalProcessedTokens' => 100, 'inputTokens' => 90, 'cachedInputTokens' => 20, 'outputTokens' => 10],
    ]]]], $checkpoint, 11);

    expect($metrics->modelCalls)->toBeNull()
        ->and($metrics->checkpoint['t3_observed_total_processed_tokens'])->toBe(100)
        ->and($metrics->checkpoint['t3_metrics_partial'])->toBeTrue();
});

it('marks a decreasing baseline partial and preserves the observed watermark', function (): void {
    $metrics = T3ThreadMetrics::baseline(['thread' => ['activities' => [[
        'kind' => 'context-window.updated',
        'payload' => ['totalProcessedTokens' => 100, 'inputTokens' => 90, 'cachedInputTokens' => 20, 'outputTokens' => 10],
    ]]]], [
        't3_input_tokens' => 40,
        't3_cached_input_tokens' => 20,
        't3_output_tokens' => 10,
        't3_model_calls' => 1,
        't3_peak_context_tokens' => 60,
        't3_counted_total_processed_tokens' => 80,
        't3_observed_total_processed_tokens' => 200,
        't3_metrics_partial' => false,
        't3_metrics_initialized' => true,
        't3_event_sequence' => 10,
    ], 11);

    expect($metrics->modelCalls)->toBeNull()
        ->and($metrics->checkpoint['t3_observed_total_processed_tokens'])->toBe(200)
        ->and($metrics->checkpoint['t3_metrics_partial'])->toBeTrue();
});

it('does not lower tokens from a stale polling snapshot', function (): void {
    $metrics = T3ThreadMetrics::fromPersisted(['thread' => ['activities' => [[
        'kind' => 'context-window.updated',
        'payload' => ['totalProcessedTokens' => 100, 'inputTokens' => 90, 'cachedInputTokens' => 20, 'outputTokens' => 10],
    ]]]], [
        't3_input_tokens' => 70,
        't3_cached_input_tokens' => 20,
        't3_output_tokens' => 10,
        't3_model_calls' => 1,
        't3_peak_context_tokens' => 90,
        't3_counted_total_processed_tokens' => 200,
        't3_observed_total_processed_tokens' => 200,
        't3_metrics_partial' => false,
        't3_metrics_initialized' => true,
        't3_event_sequence' => 12,
    ]);

    expect($metrics->tokens)->toBe(200)
        ->and($metrics->modelCalls)->toBe(1);
});

it('sums counted context-window calls and skips a repeated update', function (): void {
    $metrics = T3ThreadMetrics::fromSnapshot(['thread' => ['activities' => [
        ['kind' => 'context-window.updated', 'payload' => [
            'totalProcessedTokens' => 130, 'inputTokens' => 100, 'cachedInputTokens' => 80, 'outputTokens' => 30,
            'reasoningOutputTokens' => 9, 'lastInputTokens' => 100, 'lastCachedInputTokens' => 80, 'lastOutputTokens' => 30,
        ]],
        ['kind' => 'context-window.updated', 'payload' => [
            'totalProcessedTokens' => 130, 'inputTokens' => 100, 'cachedInputTokens' => 80, 'outputTokens' => 30,
        ]],
        ['kind' => 'other', 'payload' => ['inputTokens' => 1, 'cachedInputTokens' => 0, 'outputTokens' => 1]],
        ['kind' => 'context-window.updated', 'payload' => [
            'totalProcessedTokens' => 330, 'inputTokens' => 150, 'cachedInputTokens' => 100, 'outputTokens' => 50,
        ]],
    ]]]);

    expect($metrics->tokens)->toBe(330)
        ->and($metrics->inputTokens)->toBe(70)
        ->and($metrics->cachedInputTokens)->toBe(180)
        ->and($metrics->outputTokens)->toBe(80)
        ->and($metrics->modelCalls)->toBe(2)
        ->and($metrics->peakContextTokens)->toBe(150);
});

it('counts calls by a changed triple when the snapshot has no processed total', function (): void {
    $metrics = T3ThreadMetrics::fromSnapshot(['thread' => ['activities' => [
        ['kind' => 'context-window.updated', 'payload' => ['inputTokens' => 100, 'cachedInputTokens' => 60, 'outputTokens' => 20]],
        ['kind' => 'context-window.updated', 'payload' => ['inputTokens' => 100, 'cachedInputTokens' => 60, 'outputTokens' => 20]],
        ['kind' => 'context-window.updated', 'payload' => ['inputTokens' => 80, 'cachedInputTokens' => 10, 'outputTokens' => 15]],
    ]]]);

    expect($metrics->tokens)->toBeNull()
        ->and($metrics->inputTokens)->toBe(110)
        ->and($metrics->cachedInputTokens)->toBe(70)
        ->and($metrics->outputTokens)->toBe(35)
        ->and($metrics->modelCalls)->toBe(2)
        ->and($metrics->peakContextTokens)->toBe(100);
});

it('publishes no split when the processed total falls', function (): void {
    $metrics = T3ThreadMetrics::fromSnapshot(['thread' => ['activities' => [
        ['kind' => 'context-window.updated', 'payload' => ['totalProcessedTokens' => 100, 'inputTokens' => 80, 'cachedInputTokens' => 40, 'outputTokens' => 20]],
        ['kind' => 'context-window.updated', 'payload' => ['totalProcessedTokens' => 50, 'inputTokens' => 40, 'cachedInputTokens' => 10, 'outputTokens' => 10]],
    ]]]);

    expect($metrics->tokens)->toBe(100)
        ->and($metrics->inputTokens)->toBeNull()
        ->and($metrics->cachedInputTokens)->toBeNull()
        ->and($metrics->outputTokens)->toBeNull()
        ->and($metrics->modelCalls)->toBeNull()
        ->and($metrics->peakContextTokens)->toBeNull();
});

it('publishes no split when an advancing total lacks the call integers', function (): void {
    $metrics = T3ThreadMetrics::fromSnapshot(['thread' => ['activities' => [
        ['kind' => 'context-window.updated', 'payload' => ['totalProcessedTokens' => 100, 'inputTokens' => 80, 'cachedInputTokens' => 40, 'outputTokens' => 20]],
        ['kind' => 'context-window.updated', 'payload' => [
            'totalProcessedTokens' => 200, 'inputTokens' => 90, 'outputTokens' => 30, 'lastInputTokens' => 90, 'lastCachedInputTokens' => 10,
        ]],
    ]]]);

    expect($metrics->tokens)->toBe(200)
        ->and($metrics->inputTokens)->toBeNull()
        ->and($metrics->modelCalls)->toBeNull();
});

it('publishes no split when cached input exceeds the call input', function (): void {
    $metrics = T3ThreadMetrics::fromSnapshot(['thread' => ['activities' => [
        ['kind' => 'context-window.updated', 'payload' => ['totalProcessedTokens' => 15, 'inputTokens' => 10, 'cachedInputTokens' => 40, 'outputTokens' => 5]],
    ]]]);

    expect($metrics->tokens)->toBe(15)
        ->and($metrics->inputTokens)->toBeNull()
        ->and($metrics->cachedInputTokens)->toBeNull()
        ->and($metrics->modelCalls)->toBeNull();
});

it('leaves the split unknown when a Claude snapshot omits cached input', function (): void {
    $metrics = T3ThreadMetrics::fromSnapshot(['thread' => ['activities' => [
        ['kind' => 'context-window.updated', 'payload' => ['usedTokens' => 40000, 'totalProcessedTokens' => 80000, 'inputTokens' => 40000, 'outputTokens' => 2000]],
        ['kind' => 'context-window.updated', 'payload' => ['usedTokens' => 42000, 'totalProcessedTokens' => 70000, 'inputTokens' => 42000, 'outputTokens' => 1500]],
    ]]]);

    expect($metrics->tokens)->toBe(80000)
        ->and($metrics->inputTokens)->toBeNull()
        ->and($metrics->cachedInputTokens)->toBeNull()
        ->and($metrics->outputTokens)->toBeNull()
        ->and($metrics->modelCalls)->toBeNull()
        ->and($metrics->peakContextTokens)->toBeNull();
});
