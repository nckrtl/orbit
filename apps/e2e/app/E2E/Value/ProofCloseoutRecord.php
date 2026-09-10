<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** Retry-safe refresh and exact-cleanup state for one retained proof attempt. */
final readonly class ProofCloseoutRecord
{
    public const int SCHEMA = 1;

    public string $status;

    public function __construct(
        public string $state,
        public string $issue,
        public AttemptId $attempt,
        public string $candidateSha,
        public string $artifactSha,
        public string $mergeSha,
        public string $mainSha,
        public ?string $generationId,
        public ?string $error,
        public string $recordedAt,
    ) {
        $this->status = $state;
        if (! in_array($state, ['refresh-failed', 'refresh-succeeded', 'complete'], true)) {
            throw new InvalidArgumentException('The proof closeout state is invalid.');
        }
        TopologyTarget::assertIssue($issue);
        foreach ([$candidateSha, $artifactSha, $mergeSha, $mainSha] as $sha) {
            if (preg_match('/\A[0-9a-f]{40}\z/D', $sha) !== 1) {
                throw new InvalidArgumentException('A proof closeout Git identity is invalid.');
            }
        }
        if (
            $generationId !== null
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $generationId) !== 1
        ) {
            throw new InvalidArgumentException('The proof closeout generation identity is invalid.');
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $recordedAt) !== 1) {
            throw new InvalidArgumentException('The proof closeout time is invalid.');
        }
        if ($state === 'refresh-failed' && (! is_string($error) || $error === '')) {
            throw new InvalidArgumentException('A failed proof closeout refresh requires an error.');
        }
        if ($state !== 'refresh-failed' && ($generationId === null || $error !== null)) {
            throw new InvalidArgumentException('A successful proof closeout refresh requires its generation.');
        }
    }

    public function canReplace(self $existing): bool
    {
        if (
            $this->issue !== $existing->issue
            || $this->attempt->value !== $existing->attempt->value
            || $this->candidateSha !== $existing->candidateSha
            || $this->artifactSha !== $existing->artifactSha
            || $this->mergeSha !== $existing->mergeSha
            || $this->recordedAt < $existing->recordedAt
        ) {
            return false;
        }

        return match ($existing->state) {
            'refresh-failed' => in_array($this->state, ['refresh-failed', 'refresh-succeeded'], true),
            'refresh-succeeded' => in_array($this->state, ['refresh-succeeded', 'complete'], true)
                && $this->mainSha === $existing->mainSha
                && $this->generationId === $existing->generationId,
            'complete' => $this->mainSha === $existing->mainSha
                && $this->toArray() === $existing->toArray(),
            default => false,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'state' => $this->state,
            'issue' => $this->issue,
            'attempt_id' => $this->attempt->value,
            'candidate_sha' => $this->candidateSha,
            'artifact_sha' => $this->artifactSha,
            'merge_sha' => $this->mergeSha,
            'main_sha' => $this->mainSha,
            'generation_id' => $this->generationId,
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
                'state',
                'issue',
                'attempt_id',
                'candidate_sha',
                'artifact_sha',
                'merge_sha',
                'main_sha',
                'generation_id',
                'error',
                'recorded_at',
            ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_string($value['state'] ?? null)
            || ! is_string($value['issue'] ?? null)
            || ! is_string($value['attempt_id'] ?? null)
            || ! is_string($value['candidate_sha'] ?? null)
            || ! is_string($value['artifact_sha'] ?? null)
            || ! is_string($value['merge_sha'] ?? null)
            || ! is_string($value['main_sha'] ?? null)
            || $value['generation_id'] !== null && ! is_string($value['generation_id'])
            || $value['error'] !== null && ! is_string($value['error'])
            || ! is_string($value['recorded_at'] ?? null)
        ) {
            throw new InvalidArgumentException('The proof closeout record schema is invalid.');
        }

        return new self(
            $value['state'],
            $value['issue'],
            new AttemptId($value['attempt_id']),
            $value['candidate_sha'],
            $value['artifact_sha'],
            $value['merge_sha'],
            $value['main_sha'],
            $value['generation_id'],
            $value['error'],
            $value['recorded_at'],
        );
    }
}
