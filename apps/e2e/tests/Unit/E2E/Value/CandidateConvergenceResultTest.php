<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\CandidateConvergenceResult;
use App\E2E\Value\ConvergenceReport;
use App\E2E\Value\VerificationReport;

function candidateConvergenceResult(): CandidateConvergenceResult
{
    return new CandidateConvergenceResult(
        'converged',
        'ORB-9',
        new AttemptId(str_repeat('a', 32)),
        str_repeat('b', 40),
        str_repeat('c', 40),
        str_repeat('d', 64),
        ConvergenceReport::successful(['gateway' => true]),
        new VerificationReport(true, [
            'ready' => verificationProbeFixture(probe: 'ready'),
        ]),
        null,
        '2026-09-03T10:00:00Z',
    );
}

it('round trips complete successful candidate-convergence evidence', function (): void {
    $result = candidateConvergenceResult();

    expect(CandidateConvergenceResult::fromArray($result->toArray())->toArray())
        ->toBe($result->toArray());
});

it('rejects malformed or contradictory candidate-convergence evidence', function (
    Closure $mutate,
    string $message,
): void {
    $value = candidateConvergenceResult()->toArray();
    $mutate($value);

    expect(fn () => CandidateConvergenceResult::fromArray($value))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown field' => [
        function (array &$value): void {
            $value['unexpected'] = true;
        },
        'schema is invalid',
    ],
    'failed convergence marked successful' => [
        function (array &$value): void {
            $value['convergence'] = ['converged' => false, 'steps' => ['gateway' => false]];
        },
        'Successful candidate-convergence evidence is incomplete',
    ],
    'failed verification marked successful' => [
        function (array &$value): void {
            $value['verification']['passed'] = false;
            $value['verification']['probes']['ready']['passed'] = false;
        },
        'Successful candidate-convergence evidence is incomplete',
    ],
]);
