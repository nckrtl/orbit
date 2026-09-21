<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskThreadObservation
{
    /** @param list<AgentInputRequest> $inputRequests */
    public function __construct(
        public int $threadId,
        public TaskThreadRole $role,
        public string $sessState,
        public bool $idle,
        public ?string $pendingApprovalId,
        public ?string $pendingUserInputId,
        public ?string $lastAssistantText,
        public ?string $lastUserText,
        public bool $hasNewCommitsSinceThreadStart,
        public ?string $prUrl,
        public ?string $ciSummary,
        public bool $available = true,
        public ?string $error = null,
        public array $inputRequests = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'thread_id' => $this->threadId,
            'role' => $this->role->value,
            'state' => $this->sessState,
            'available' => $this->available,
            'error' => $this->error,
            'input_requests' => array_map(static fn (AgentInputRequest $request): array => $request->toArray(), $this->inputRequests),
            'idle' => $this->idle,
            'pending_approval_id' => $this->pendingApprovalId,
            'pending_user_input_id' => $this->pendingUserInputId,
            'last_assistant_text' => $this->lastAssistantText,
            'last_user_text' => $this->lastUserText,
            'has_new_commits_since_thread_start' => $this->hasNewCommitsSinceThreadStart,
            'pr_url' => $this->prUrl,
            'ci_summary' => $this->ciSummary,
        ];
    }
}
