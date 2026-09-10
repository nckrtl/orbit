<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** Ordered interactive-review actions bound to one captured proof attempt. */
final readonly class ProofReviewRecord
{
    public const int SCHEMA = 1;

    /** @param list<ProofReviewAction> $actions */
    public function __construct(
        public string $issue,
        public string $candidateSha,
        public AttemptId $attempt,
        public array $actions,
        public string $updatedAt,
    ) {
        TopologyTarget::assertIssue($issue);
        if (preg_match('/\A[0-9a-f]{40}\z/D', $candidateSha) !== 1) {
            throw new InvalidArgumentException('The proof review candidate SHA is invalid.');
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $updatedAt) !== 1) {
            throw new InvalidArgumentException('The proof review record time is invalid.');
        }
        $identities = [];
        foreach ($actions as $action) {
            if (isset($identities[$action->id])) {
                throw new InvalidArgumentException('The proof review actions must have unique ordered identities.');
            }
            $identities[$action->id] = true;
        }
    }

    public static function empty(string $issue, string $candidateSha, AttemptId $attempt, string $updatedAt): self
    {
        return new self($issue, $candidateSha, $attempt, [], $updatedAt);
    }

    public function action(string $id): ?ProofReviewAction
    {
        foreach ($this->actions as $action) {
            if ($action->id === $id) {
                return $action;
            }
        }

        return null;
    }

    public function hasActions(): bool
    {
        return $this->actions !== [];
    }

    public function withAction(ProofReviewAction $action, string $updatedAt): self
    {
        $actions = $this->actions;
        foreach ($actions as $index => $existing) {
            if ($existing->id !== $action->id) {
                continue;
            }
            if (! $existing->sameIdentity($action)) {
                throw new InvalidArgumentException('A proof review action identity cannot be replaced.');
            }
            if ($existing->status !== 'incomplete' || $action->status === 'incomplete') {
                if ($existing->toArray() === $action->toArray()) {
                    return new self($this->issue, $this->candidateSha, $this->attempt, $actions, $updatedAt);
                }

                throw new InvalidArgumentException('A completed proof review action is immutable.');
            }
            $actions[$index] = $action;

            return new self($this->issue, $this->candidateSha, $this->attempt, $actions, $updatedAt);
        }
        $actions[] = $action;

        return new self($this->issue, $this->candidateSha, $this->attempt, $actions, $updatedAt);
    }

    public function canReplace(self $existing): bool
    {
        if (
            $this->issue !== $existing->issue
            || $this->candidateSha !== $existing->candidateSha
            || $this->attempt->value !== $existing->attempt->value
            || $this->updatedAt < $existing->updatedAt
            || count($this->actions) < count($existing->actions)
        ) {
            return false;
        }
        foreach ($existing->actions as $index => $previous) {
            $next = $this->actions[$index] ?? null;
            if (! $next instanceof ProofReviewAction || ! $previous->sameIdentity($next)) {
                return false;
            }
            if ($previous->status === 'incomplete') {
                if (! in_array($next->status, ['incomplete', 'passed', 'failed'], true)) {
                    return false;
                }
            } elseif ($previous->toArray() !== $next->toArray()) {
                return false;
            }
        }

        return true;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(
            $this->payload(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->payload(), 'fingerprint' => $this->fingerprint()];
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
                'actions',
                'updated_at',
                'fingerprint',
            ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_string($value['issue'] ?? null)
            || ! is_string($value['candidate_sha'] ?? null)
            || ! is_string($value['attempt_id'] ?? null)
            || ! is_array($value['actions'] ?? null)
            || ! array_is_list($value['actions'])
            || ! is_string($value['updated_at'] ?? null)
            || ! is_string($value['fingerprint'] ?? null)
        ) {
            throw new InvalidArgumentException('The proof review record schema is invalid.');
        }
        $record = new self(
            $value['issue'],
            $value['candidate_sha'],
            new AttemptId($value['attempt_id']),
            array_map(static function (mixed $action): ProofReviewAction {
                if (! is_array($action)) {
                    throw new InvalidArgumentException('The proof review action schema is invalid.');
                }

                return ProofReviewAction::fromArray($action);
            }, $value['actions']),
            $value['updated_at'],
        );
        if (! hash_equals($record->fingerprint(), $value['fingerprint'])) {
            throw new InvalidArgumentException('The proof review record fingerprint is invalid.');
        }

        return $record;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema' => self::SCHEMA,
            'issue' => $this->issue,
            'candidate_sha' => $this->candidateSha,
            'attempt_id' => $this->attempt->value,
            'actions' => array_map(
                static fn (ProofReviewAction $action): array => $action->toArray(),
                $this->actions,
            ),
            'updated_at' => $this->updatedAt,
        ];
    }
}
