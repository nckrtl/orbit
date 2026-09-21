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
        public int $taskId,
        public string $taskStatus,
        public string $taskTitle,
        public string $taskBrief,
        public string $groupStatus,
        public string $title,
        public string $brief,
        public bool $hasPendingSubtasks,
        public ?string $prUrl,
        public ?string $ciSummary,
        public array $threads,
        public bool $available = true,
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
            'available' => $this->available,
            'task_id' => $this->taskId,
            'task_status' => $this->taskStatus,
            'task_title' => $this->taskTitle,
            'task_brief' => $this->taskBrief,
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
