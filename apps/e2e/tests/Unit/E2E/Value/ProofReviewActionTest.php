<?php

declare(strict_types=1);

use App\E2E\Value\ProofReviewAction;

it('round-trips an action and permits only one completion', function (): void {
    $pending = ProofReviewAction::incomplete(
        'inspect-gateway',
        'exec',
        'gateway',
        true,
        ['orbit', 'doctor', '--json'],
        hash('sha256', 'review input'),
        '2026-09-10T10:00:00Z',
    );
    $completed = $pending->complete('passed', 0, '{"ok":true}', '', null, '2026-09-10T10:01:00Z');

    expect(ProofReviewAction::fromArray($completed->toArray())->toArray())
        ->toBe($completed->toArray())
        ->and($completed->stdinSha256)
        ->toBe(hash('sha256', 'review input'))
        ->and(fn () => $completed->complete('passed', 0, '', '', null, '2026-09-10T10:02:00Z'))
        ->toThrow(InvalidArgumentException::class, 'cannot be completed again');
});

it('uses stdin content in the immutable action identity without storing it', function (): void {
    $first = ProofReviewAction::incomplete(
        'inspect-gateway',
        'exec',
        'gateway',
        true,
        ['sh'],
        hash('sha256', 'first input'),
        '2026-09-10T10:00:00Z',
    );
    $second = ProofReviewAction::incomplete(
        'inspect-gateway',
        'exec',
        'gateway',
        true,
        ['sh'],
        hash('sha256', 'second input'),
        '2026-09-10T10:00:00Z',
    );

    expect($first->sameIdentity($second))
        ->toBeFalse()
        ->and(json_encode($first->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('first input');
});

it('rejects invalid Node and incomplete result state', function (): void {
    expect(fn () => ProofReviewAction::incomplete(
        'inspect',
        'exec',
        'app-prod-3',
        false,
        ['true'],
        null,
        '2026-09-10T10:00:00Z',
    ))->toThrow(InvalidArgumentException::class, 'Node is invalid')
        ->and(fn () => new ProofReviewAction(
            'inspect',
            'exec',
            'gateway',
            true,
            'incomplete',
            ['true'],
            null,
            0,
            '',
            '',
            null,
            '2026-09-10T10:00:00Z',
            null,
        ))->toThrow(InvalidArgumentException::class, 'cannot carry a result');
});
