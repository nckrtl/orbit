<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use JsonException;

/**
 * The receipt an agent writes with `.git/orbit/run` to end its turn. A receipt without a
 * known outcome and a summary is invalid and never advances a task.
 */
final readonly class TaskRunReceipt
{
    private function __construct(
        public string $hash,
        public ?TaskRunOutcome $outcome,
        public string $summary,
        public ?TaskRunPullRequest $pullRequest = null,
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

        return new self($hash, TaskRunOutcome::tryFrom($data['outcome']), trim($data['summary']), TaskRunPullRequest::fromArray($data['pull_request'] ?? null));
    }

    public function fits(TaskThreadRole $role): bool
    {
        return $this->outcome?->fits($role) ?? false;
    }
}
