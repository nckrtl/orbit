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
        ->and(T3ThreadMetrics::fromSnapshot(['thread' => 'nope'])->lineDiff)->toBeNull();
});
