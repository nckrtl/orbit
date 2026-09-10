<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\ProofReviewAction;
use App\E2E\Value\ProofReviewRecord;

it('appends ordered unique actions and retains completed history', function (): void {
    $record = ProofReviewRecord::empty(
        'ORB-230',
        str_repeat('a', 40),
        new AttemptId(str_repeat('b', 32)),
        '2026-09-10T10:00:00Z',
    );
    $pending = ProofReviewAction::incomplete(
        'inspect',
        'shell',
        'app-prod-2',
        true,
        [],
        null,
        '2026-09-10T10:01:00Z',
    );
    $withPending = $record->withAction($pending, '2026-09-10T10:01:00Z');
    $completed = $withPending->withAction(
        $pending->complete('passed', null, '', '', 'Observed healthy state.', '2026-09-10T10:02:00Z'),
        '2026-09-10T10:02:00Z',
    );

    expect($completed->hasActions())
        ->toBeTrue()
        ->and($completed->action('inspect')?->status)
        ->toBe('passed')
        ->and($completed->canReplace($withPending))
        ->toBeTrue()
        ->and(ProofReviewRecord::fromArray($completed->toArray())->toArray())
        ->toBe($completed->toArray());
});

it('rejects duplicate action identities', function (): void {
    $action = ProofReviewAction::incomplete(
        'inspect',
        'shell',
        'gateway',
        false,
        [],
        null,
        '2026-09-10T10:00:00Z',
    );

    expect(fn () => new ProofReviewRecord(
        'ORB-230',
        str_repeat('a', 40),
        new AttemptId(str_repeat('b', 32)),
        [$action, $action],
        '2026-09-10T10:00:00Z',
    ))->toThrow(InvalidArgumentException::class, 'unique ordered identities');
});
