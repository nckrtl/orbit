<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * One structured reading of a task group: its own state plus every task
 * thread Orbit started for it.
 */
final readonly class TaskSessionObservation
{
    /**
     * @param  list<TaskThreadObservation>  $threads
     */
    public function __construct(
        public int $groupId,
        public TaskGroupStatus $groupStatus,
        public ?int $currentTaskId,
        public ?string $prUrl,
        public ?string $ciSummary,
        public array $threads,
    ) {}

    public function thread(string $threadId): ?TaskThreadObservation
    {
        foreach ($this->threads as $thread) {
            if ($thread->threadId === $threadId) {
                return $thread;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     group_id: int,
     *     group_status: string,
     *     current_task_id: int|null,
     *     pr_url: string|null,
     *     ci_summary: string|null,
     *     threads: list<array<string, mixed>>,
     * }
     */
    public function toArray(): array
    {
        return [
            'group_id' => $this->groupId,
            'group_status' => $this->groupStatus->value,
            'current_task_id' => $this->currentTaskId,
            'pr_url' => $this->prUrl,
            'ci_summary' => $this->ciSummary,
            'threads' => array_map(
                static fn (TaskThreadObservation $thread): array => $thread->toArray(),
                $this->threads,
            ),
        ];
    }
}
