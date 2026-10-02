<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use JsonException;

/**
 * The receipt an agent writes with `.git/orbit/turn` to end its turn. A receipt without a
 * known outcome and a summary is invalid and never advances a task. So is a `blocked`
 * receipt without a question for the operator. The receipt also carries the agent's
 * confirmation of each subtask deliverable (ADR 0133).
 */
final readonly class TaskTurnReceipt
{
    /** @param array<string, string> $deliverables the evidence for each confirmed deliverable, by ID */
    private function __construct(
        public string $hash,
        public ?TaskTurnOutcome $outcome,
        public string $summary,
        public ?TaskTurnPullRequest $pullRequest = null,
        public ?string $question = null,
        public array $deliverables = [],
        public ?int $threadId = null,
        public ?string $cause = null,
    ) {}

    public static function parse(string $contents): self
    {
        $hash = hash('sha256', $contents);
        try {
            $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self($hash, null, '');
        }
        if (! is_array($data) || ! is_string($data['outcome'] ?? null) || ! is_string($data['summary'] ?? null) || trim($data['summary']) === '') {
            return new self($hash, null, '');
        }
        $outcome = TaskTurnOutcome::tryFrom($data['outcome']);
        $question = null;
        if ($outcome === TaskTurnOutcome::Blocked) {
            $question = is_string($data['question'] ?? null) ? trim($data['question']) : '';
            if ($question === '') {
                return new self($hash, null, '');
            }
        }
        $cause = self::cause($data['cause'] ?? null);
        if ($cause === false) {
            return new self($hash, null, '');
        }

        return new self($hash, $outcome, trim($data['summary']), TaskTurnPullRequest::fromArray($data['pull_request'] ?? null), $question, self::confirmations($data['deliverables'] ?? null), self::threadId($data['thread'] ?? null), $cause);
    }

    /** The same receipt, named as written by this Orbit thread. The content hash stays the hash of the file. */
    public function withThread(int $threadId): self
    {
        return new self($this->hash, $this->outcome, $this->summary, $this->pullRequest, $this->question, $this->deliverables, $threadId, $this->cause);
    }

    /** @return string|null|false false when the receipt names a cause that is not one of the five */
    private static function cause(mixed $value): string|null|false
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || QuestionCause::tryFrom($value) === null) {
            return false;
        }

        return $value;
    }

    private static function threadId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /** @return array<string, string> */
    private static function confirmations(mixed $data): array
    {
        $confirmations = [];
        foreach (is_array($data) ? $data : [] as $id => $evidence) {
            if (is_string($id) && is_string($evidence) && trim($evidence) !== '') {
                $confirmations[$id] = trim($evidence);
            }
        }

        return $confirmations;
    }

    public function fits(TaskThreadRole $role): bool
    {
        return $this->outcome?->fits($role) ?? false;
    }

    /**
     * The text Orbit stores as the receipt's comment. A blocked receipt adds its question for the operator.
     */
    public function body(): string
    {
        return $this->question === null ? $this->summary : $this->summary."\n\nQuestion: ".$this->question;
    }
}
