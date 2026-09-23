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

    /**
     * The evidence Jev reads for a role's blocked question: the briefs and that role's own thread, without rubric reminders.
     *
     * @return array{classification_role: string, group_title: string, group_brief: string, task_title: string, task_brief: string, thread: array{state: string, recent_messages: list<array{id: string, kind: string, label: string, text: string, at: string}>}}|null
     */
    public function transcriptEvidence(TaskThreadRole $role): ?array
    {
        $thread = $this->thread($role);
        if ($thread === null) {
            return null;
        }

        return [
            'classification_role' => $role->value,
            'group_title' => $this->title,
            'group_brief' => $this->brief,
            'task_title' => $this->taskTitle,
            'task_brief' => $this->taskBrief,
            'thread' => [
                'state' => $thread->sessState,
                'recent_messages' => array_values(array_filter(
                    $thread->recentMessages,
                    static fn (array $entry): bool => ! ($entry['kind'] === 'message' && $entry['label'] === 'user' && TaskRubricReminder::isReminder($entry['text'])),
                )),
            ],
        ];
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
