<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\ProofReviewAction;
use App\E2E\Value\ProofReviewEvaluation;
use App\E2E\Value\ProofReviewRecord;

it('blocks required incomplete and failed actions while listing exploratory failures separately', function (): void {
    $started = '2026-09-10T10:00:00Z';
    $finished = '2026-09-10T10:01:00Z';
    $requiredIncomplete = ProofReviewAction::incomplete('required-pending', 'shell', 'gateway', true, [], null, $started);
    $requiredFailed = ProofReviewAction::incomplete('required-failed', 'exec', 'app-dev', true, ['false'], null, $started)
        ->complete('failed', 1, '', 'failed', 'Unexpected result.', $finished);
    $exploratoryFailed = ProofReviewAction::incomplete('explore-failed', 'exec', 'app-prod', false, ['false'], null, $started)
        ->complete('failed', 1, '', 'failed', null, $finished);
    $record = new ProofReviewRecord(
        'ORB-230',
        str_repeat('a', 40),
        new AttemptId(str_repeat('b', 32)),
        [$requiredIncomplete, $requiredFailed, $exploratoryFailed],
        $finished,
    );

    $evaluation = ProofReviewEvaluation::forRecord($record, '2026-09-10T10:02:00Z');

    expect($evaluation->status)
        ->toBe('blocked')
        ->and($evaluation->requiredIncomplete)
        ->toBe(['required-pending'])
        ->and($evaluation->requiredFailed)
        ->toBe(['required-failed'])
        ->and($evaluation->exploratoryFailed)
        ->toBe(['explore-failed'])
        ->and(ProofReviewEvaluation::fromArray($evaluation->toArray())->toArray())
        ->toBe($evaluation->toArray());
});

it('is ready when only exploratory checks fail', function (): void {
    $action = ProofReviewAction::incomplete(
        'explore',
        'exec',
        'gateway',
        false,
        ['false'],
        null,
        '2026-09-10T10:00:00Z',
    )->complete('failed', 1, '', 'failed', null, '2026-09-10T10:01:00Z');
    $record = new ProofReviewRecord(
        'ORB-230',
        str_repeat('a', 40),
        new AttemptId(str_repeat('b', 32)),
        [$action],
        '2026-09-10T10:01:00Z',
    );

    expect(ProofReviewEvaluation::forRecord($record, '2026-09-10T10:02:00Z')->status)->toBe('ready');
});
