<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use JsonException;

/**
 * The receipt an agent writes with `.git/orbit/run` to end its turn. A receipt without a
 * known outcome and a summary is invalid and never advances a task. So is a `blocked`
 * receipt without a question for the operator.
 */
final readonly class TaskRunReceipt
{
    private function __construct(
        public string $hash,
        public ?TaskRunOutcome $outcome,
        public string $summary,
        public ?TaskRunPullRequest $pullRequest = null,
        public ?string $question = null,
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
        $outcome = TaskRunOutcome::tryFrom($data['outcome']);
        $question = null;
        if ($outcome === TaskRunOutcome::Blocked) {
            $question = is_string($data['question'] ?? null) ? trim($data['question']) : '';
            if ($question === '') {
                return new self($hash, null, '');
            }
        }

        return new self($hash, $outcome, trim($data['summary']), TaskRunPullRequest::fromArray($data['pull_request'] ?? null), $question);
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
