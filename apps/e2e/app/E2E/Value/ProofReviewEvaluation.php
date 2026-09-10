<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** Derived review readiness; it never constitutes formal approval. */
final readonly class ProofReviewEvaluation
{
    public const int SCHEMA = 1;

    /**
     * @param  list<string>  $requiredIncomplete
     * @param  list<string>  $requiredFailed
     * @param  list<string>  $exploratoryFailed
     */
    public function __construct(
        public string $issue,
        public string $candidateSha,
        public AttemptId $attempt,
        public string $status,
        public array $requiredIncomplete,
        public array $requiredFailed,
        public array $exploratoryFailed,
        public string $evaluatedAt,
    ) {
        TopologyTarget::assertIssue($issue);
        if (preg_match('/\A[0-9a-f]{40}\z/D', $candidateSha) !== 1) {
            throw new InvalidArgumentException('The proof review evaluation candidate SHA is invalid.');
        }
        if (! in_array($status, ['ready', 'blocked'], true)) {
            throw new InvalidArgumentException('The proof review evaluation status is invalid.');
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $evaluatedAt) !== 1) {
            throw new InvalidArgumentException('The proof review evaluation time is invalid.');
        }
        $seen = [];
        foreach ([$requiredIncomplete, $requiredFailed, $exploratoryFailed] as $actions) {
            foreach ($actions as $action) {
                if (
                    preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $action) !== 1
                    || isset($seen[$action])
                ) {
                    throw new InvalidArgumentException('A proof review evaluation action identity is invalid.');
                }
                $seen[$action] = true;
            }
        }
        $expected = $requiredIncomplete === [] && $requiredFailed === [] ? 'ready' : 'blocked';
        if ($status !== $expected) {
            throw new InvalidArgumentException('The proof review evaluation status does not match its required actions.');
        }
    }

    public static function forRecord(ProofReviewRecord $record, string $evaluatedAt): self
    {
        $incomplete = [];
        $failed = [];
        $exploratory = [];
        foreach ($record->actions as $action) {
            if ($action->required && $action->status === 'incomplete') {
                $incomplete[] = $action->id;
            } elseif ($action->required && $action->status === 'failed') {
                $failed[] = $action->id;
            } elseif (! $action->required && $action->status === 'failed') {
                $exploratory[] = $action->id;
            }
        }

        return new self(
            $record->issue,
            $record->candidateSha,
            $record->attempt,
            $incomplete === [] && $failed === [] ? 'ready' : 'blocked',
            $incomplete,
            $failed,
            $exploratory,
            $evaluatedAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'issue' => $this->issue,
            'candidate_sha' => $this->candidateSha,
            'attempt_id' => $this->attempt->value,
            'status' => $this->status,
            'required_incomplete' => $this->requiredIncomplete,
            'required_failed' => $this->requiredFailed,
            'exploratory_failed' => $this->exploratoryFailed,
            'evaluated_at' => $this->evaluatedAt,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== [
                'schema',
                'issue',
                'candidate_sha',
                'attempt_id',
                'status',
                'required_incomplete',
                'required_failed',
                'exploratory_failed',
                'evaluated_at',
            ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_string($value['issue'] ?? null)
            || ! is_string($value['candidate_sha'] ?? null)
            || ! is_string($value['attempt_id'] ?? null)
            || ! is_string($value['status'] ?? null)
            || ! is_array($value['required_incomplete'] ?? null)
            || ! is_array($value['required_failed'] ?? null)
            || ! is_array($value['exploratory_failed'] ?? null)
            || ! is_string($value['evaluated_at'] ?? null)
        ) {
            throw new InvalidArgumentException('The proof review evaluation schema is invalid.');
        }

        return new self(
            $value['issue'],
            $value['candidate_sha'],
            new AttemptId($value['attempt_id']),
            $value['status'],
            $value['required_incomplete'],
            $value['required_failed'],
            $value['exploratory_failed'],
            $value['evaluated_at'],
        );
    }
}
