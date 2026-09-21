<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Facts observed on one task thread: what T3 reports about the session and
 * what the Gateway already knows about the shared workspace.
 */
final readonly class TaskThreadObservation
{
    public function __construct(
        public string $threadId,
        public TaskThreadRole $role,
        public ?int $taskId,
        public ?string $sessionState,
        public bool $idle,
        public ?string $pendingApprovalId,
        public ?string $pendingUserInputId,
        public ?string $lastAssistantText,
        public ?string $lastUserText,
        public ?int $newCommits,
        public ?string $prUrl,
        public ?string $ciSummary,
    ) {}

    /**
     * @return array{
     *     thread_id: string,
     *     role: string,
     *     task_id: int|null,
     *     session_state: string|null,
     *     idle: bool,
     *     pending_approval_id: string|null,
     *     pending_user_input_id: string|null,
     *     last_assistant_text: string|null,
     *     last_user_text: string|null,
     *     new_commits: int|null,
     *     pr_url: string|null,
     *     ci_summary: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'thread_id' => $this->threadId,
            'role' => $this->role->value,
            'task_id' => $this->taskId,
            'session_state' => $this->sessionState,
            'idle' => $this->idle,
            'pending_approval_id' => $this->pendingApprovalId,
            'pending_user_input_id' => $this->pendingUserInputId,
            'last_assistant_text' => $this->lastAssistantText,
            'last_user_text' => $this->lastUserText,
            'new_commits' => $this->newCommits,
            'pr_url' => $this->prUrl,
            'ci_summary' => $this->ciSummary,
        ];
    }
}
