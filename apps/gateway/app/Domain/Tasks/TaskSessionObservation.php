<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskSessionObservation
{
    /**
     * @param  list<TaskThreadObservation>  $threads
     */
    public function __construct(
        public int $groupId,
        public string $groupStatus,
        public string $title,
        public string $brief,
        public bool $hasPendingSubtasks,
        public ?string $prUrl,
        public ?string $ciSummary,
        public array $threads,
    ) {}

    public function thread(TaskThreadRole $role): ?TaskThreadObservation
    {
        foreach ($this->threads as $thread) {
            if ($thread->role === $role) {
                return $thread;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'group_id' => $this->groupId,
            'group_status' => $this->groupStatus,
            'title' => $this->title,
            'brief' => $this->brief,
            'has_pending_subtasks' => $this->hasPendingSubtasks,
            'pr_url' => $this->prUrl,
            'ci_summary' => $this->ciSummary,
            'threads' => array_map(
                static fn (TaskThreadObservation $thread): array => $thread->toArray(),
                $this->threads,
            ),
        ];
    }
}
