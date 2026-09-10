<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\ProofCloseoutRecord;

function closeoutRecord(string $state, ?string $generation, ?string $error, string $time): ProofCloseoutRecord
{
    return new ProofCloseoutRecord(
        $state,
        'AUX-230',
        new AttemptId(str_repeat('a', 32)),
        str_repeat('b', 40),
        str_repeat('c', 40),
        str_repeat('d', 40),
        str_repeat('e', 40),
        $generation,
        $error,
        $time,
    );
}

it('permits retry from failed refresh through successful refresh and completion', function (): void {
    $failed = closeoutRecord('refresh-failed', null, 'Refresh failed.', '2026-09-10T10:00:00Z');
    $refreshed = closeoutRecord('refresh-succeeded', 'generation-1', null, '2026-09-10T10:01:00Z');
    $complete = closeoutRecord('complete', 'generation-1', null, '2026-09-10T10:02:00Z');

    expect($refreshed->canReplace($failed))
        ->toBeTrue()
        ->and($complete->canReplace($refreshed))
        ->toBeTrue()
        ->and($failed->canReplace($refreshed))
        ->toBeFalse()
        ->and(ProofCloseoutRecord::fromArray($complete->toArray())->toArray())
        ->toBe($complete->toArray());
});

it('keeps replacement retry states separate from ordinary refresh', function (): void {
    $failed = closeoutRecord('replacement-failed', null, 'Cleanup pending.', '2026-09-10T10:00:00Z');
    $installed = closeoutRecord('replacement-succeeded', 'generation-2', null, '2026-09-10T10:01:00Z');
    $complete = closeoutRecord('complete', 'generation-2', null, '2026-09-10T10:02:00Z');
    $refreshed = closeoutRecord('refresh-succeeded', 'generation-2', null, '2026-09-10T10:01:00Z');

    expect($installed->canReplace($failed))
        ->toBeTrue()
        ->and($complete->canReplace($installed))
        ->toBeTrue()
        ->and($refreshed->canReplace($failed))
        ->toBeFalse();
});

it('rejects successful refresh without a generation', function (): void {
    expect(fn () => closeoutRecord('refresh-succeeded', null, null, '2026-09-10T10:00:00Z'))
        ->toThrow(InvalidArgumentException::class, 'requires its generation');
});

it('permits a failed refresh retry against newer main while retaining fixed identities', function (): void {
    $failed = closeoutRecord('refresh-failed', null, 'Refresh failed.', '2026-09-10T10:00:00Z');
    $retry = $failed->toArray();
    $retry['state'] = 'refresh-succeeded';
    $retry['main_sha'] = str_repeat('f', 40);
    $retry['generation_id'] = 'generation-2';
    $retry['error'] = null;
    $retry['recorded_at'] = '2026-09-10T10:01:00Z';

    expect(ProofCloseoutRecord::fromArray($retry)->canReplace($failed))->toBeTrue();
});
