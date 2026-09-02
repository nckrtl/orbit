<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * Immutable evidence that a later accepted candidate converged without rerunning feature actions.
 *
 * @mago-expect lint:cyclomatic-complexity,excessive-parameter-list The evidence record validates every field at construction.
 */
final readonly class CandidateConvergenceResult
{
    public const int SCHEMA = 1;

    public function __construct(
        public string $status,
        public string $issue,
        public AttemptId $attempt,
        public string $candidateSha,
        public string $candidateTree,
        public string $equivalenceSha256,
        public ?ConvergenceReport $convergence,
        public VerificationReport $verification,
        public ?string $error,
        public string $recordedAt,
    ) {
        TopologyTarget::assertIssue($issue);
        if (! in_array($status, ['converged', 'diagnosis'], true)) {
            throw new InvalidArgumentException('The candidate-convergence status is invalid.');
        }
        foreach ([$candidateSha, $candidateTree] as $identity) {
            if (preg_match('/\A[0-9a-f]{40}\z/D', $identity) !== 1) {
                throw new InvalidArgumentException('A candidate-convergence Git identity is invalid.');
            }
        }
        if (preg_match('/\A[0-9a-f]{64}\z/D', $equivalenceSha256) !== 1) {
            throw new InvalidArgumentException('The candidate-convergence equivalence fingerprint is invalid.');
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $recordedAt) !== 1) {
            throw new InvalidArgumentException('The candidate-convergence time is invalid.');
        }
        if (
            $status === 'converged'
            && ($convergence?->converged !== true
            || ! $verification->passed
            || $error !== null)
        ) {
            throw new InvalidArgumentException('Successful candidate-convergence evidence is incomplete.');
        }
        if (
            $status === 'diagnosis'
            && (! is_string($error)
            || $error === ''
            || $verification->passed)
        ) {
            throw new InvalidArgumentException('Failed candidate-convergence evidence is incomplete.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => $this->status,
            'issue' => $this->issue,
            'attempt_id' => $this->attempt->value,
            'candidate_sha' => $this->candidateSha,
            'candidate_tree' => $this->candidateTree,
            'equivalence_sha256' => $this->equivalenceSha256,
            'convergence' => $this->convergence?->toArray(),
            'verification' => $this->verification->toArray(),
            'error' => $this->error,
            'recorded_at' => $this->recordedAt,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== [
                'schema',
                'status',
                'issue',
                'attempt_id',
                'candidate_sha',
                'candidate_tree',
                'equivalence_sha256',
                'convergence',
                'verification',
                'error',
                'recorded_at',
            ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_string($value['status'] ?? null)
            || ! is_string($value['issue'] ?? null)
            || ! is_string($value['attempt_id'] ?? null)
            || ! is_string($value['candidate_sha'] ?? null)
            || ! is_string($value['candidate_tree'] ?? null)
            || ! is_string($value['equivalence_sha256'] ?? null)
            || $value['convergence'] !== null
            && ! is_array($value['convergence'])
            || ! is_array($value['verification'] ?? null)
            || $value['error'] !== null
            && ! is_string($value['error'])
            || ! is_string($value['recorded_at'] ?? null)
        ) {
            throw new InvalidArgumentException('The candidate-convergence evidence schema is invalid.');
        }

        return new self(
            $value['status'],
            $value['issue'],
            new AttemptId($value['attempt_id']),
            $value['candidate_sha'],
            $value['candidate_tree'],
            $value['equivalence_sha256'],
            is_array($value['convergence']) ? ConvergenceReport::fromArray($value['convergence']) : null,
            VerificationReport::fromArray($value['verification']),
            $value['error'],
            $value['recorded_at'],
        );
    }
}
