<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\ProofResult;
use App\E2E\Value\ProofStatus;

describe('ProofResult', function () {
    it('prints a compact verdict with per-action exit codes and the failing tails', function () {
        $result = new ProofResult(
            'NCK-12',
            new AttemptId(str_repeat('a', 32)),
            ProofStatus::Diagnosis,
            str_repeat('b', 40),
            [
                ['id' => 'setup-1', 'node' => 'gateway', 'exit_code' => 0, 'stdout' => 'ok', 'stderr' => ''],
                [
                    'id' => 'check',
                    'node' => 'app-dev',
                    'exit_code' => 3,
                    'stdout' => str_repeat('x', 5_000),
                    'stderr' => 'boom',
                ],
            ],
            'proof phase acceptance failed: Proof acceptance action [check] failed with exit code 3.',
            '2026-08-30T10:00:00Z',
        );

        $payload = $result->toArray();

        expect(array_keys($payload))
            ->toBe([
                'status',
                'issue',
                'attempt_id',
                'candidate_sha',
                'actions',
                'recorded_at',
                'failed_action',
                'error',
            ])
            ->and($payload['actions'])
            ->toBe([
                ['id' => 'setup-1', 'node' => 'gateway', 'exit_code' => 0],
                ['id' => 'check', 'node' => 'app-dev', 'exit_code' => 3],
            ])
            ->and($payload['failed_action']['id'])
            ->toBe('check')
            ->and(strlen((string) $payload['failed_action']['stdout_tail']))
            ->toBe(ProofResult::TAIL_LIMIT)
            ->and($payload['failed_action']['stderr_tail'])
            ->toBe('boom');
    });

    it('rejects a proved verdict that carries a failure', function () {
        expect(
            fn () => new ProofResult(
                'NCK-12',
                new AttemptId(str_repeat('a', 32)),
                ProofStatus::Proved,
                str_repeat('b', 40),
                [
                    ['id' => 'check', 'node' => 'app-dev', 'exit_code' => 1, 'stdout' => '', 'stderr' => ''],
                ],
                null,
                '2026-08-30T10:00:00Z',
            ),
        )
            ->toThrow(InvalidArgumentException::class, 'cannot carry a failure')
            ->and(
                fn () => new ProofResult(
                    'NCK-12',
                    new AttemptId(str_repeat('a', 32)),
                    ProofStatus::Proved,
                    str_repeat('b', 40),
                    [],
                    'x',
                    '2026-08-30T10:00:00Z',
                ),
            )
            ->toThrow(InvalidArgumentException::class, 'cannot carry a failure');
    });
});
