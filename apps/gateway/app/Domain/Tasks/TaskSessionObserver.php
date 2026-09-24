<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Support\Carbon;

final readonly class TaskSessionObserver
{
    public function __construct(private AgentThreadObserver $threads, private TaskWorkspaceDiffReader $diff) {}

    /**
     * Observes the task's implementer and the group's reviewer. Only the thread that acts in the task's
     * current phase defers the task while it works: the implementer while the task runs, the reviewer
     * while it is in review. The other thread's work does not hide the acting thread.
     */
    public function observe(TaskGroup $group, Task $task): TaskSessionObservation
    {
        $group->loadMissing(['app', 'tasks', 'taskable']);
        $observations = [];
        $actingRole = $task->status === TaskStatus::Reviewing ? TaskThreadRole::Reviewer : TaskThreadRole::Implementer;
        $actingThreadWorks = false;
        foreach (AgentThread::query()->where('task_group_id', $group->id)->orderBy('id')->get() as $thread) {
            $role = TaskThreadRole::tryFrom($thread->role);
            $belongsToTask = $thread->task_id === $task->id;
            $sharedReviewer = $thread->task_id === null && $role === TaskThreadRole::Reviewer;
            if ($role === null || (! $belongsToTask && ! $sharedReviewer)) {
                continue;
            }
            $observation = $this->threads->observe($thread);
            $actingThreadWorks = $actingThreadWorks || ($role === $actingRole && $observation?->state === AgentThreadState::Working);
            $observations[] = [$thread, $role, $observation];
        }
        if ($actingThreadWorks) {
            $observations = [];
        }
        $threads = [];
        $hasNewCommits = $observations !== [] && $this->hasNewCommits($group);
        foreach ($observations as [$thread, $role, $observation]) {
            $requests = $observation->inputRequests ?? [];
            $approval = $question = null;
            foreach ($requests as $request) {
                if ($request->kind === 'approval') {
                    $approval ??= $request->id;
                } elseif ($request->kind === 'question') {
                    $question ??= $request->id;
                }
            }
            $threads[] = new TaskThreadObservation(
                threadId: $thread->id, role: $role,
                sessState: $observation->state->value ?? $thread->state->value ?? 'unknown',
                idle: in_array($observation?->state, [AgentThreadState::Idle, AgentThreadState::Done], true),
                pendingApprovalId: $approval, pendingUserInputId: $question,
                lastAssistantText: $observation?->lastText('assistant'), lastUserText: $observation?->lastText('user'),
                hasNewCommitsSinceThreadStart: $hasNewCommits, prUrl: $group->pr_url, ciSummary: null,
                available: $observation !== null && $observation->state !== null,
                error: $observation?->error, inputRequests: $requests,
                recentMessages: $observation === null ? [] : $this->recentMessages($observation->entries),
                turnId: $observation?->turnId,
            );
        }

        return new TaskSessionObservation(
            taskId: $task->id, taskStatus: $task->status->value, taskTitle: $task->title, taskBrief: $task->brief,
            groupId: $group->id, groupStatus: $group->status->value, title: $group->title, brief: $group->brief,
            hasPendingSubtasks: $group->tasks->contains(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Reserved, TaskStatus::Running, TaskStatus::Reviewing], true)),
            prUrl: $group->pr_url, ciSummary: null, threads: $threads,
            available: ! array_any($threads, static fn (TaskThreadObservation $thread): bool => ! $thread->available),
        );
    }

    private function hasNewCommits(TaskGroup $group): bool
    {
        $instance = $group->taskable;

        if (! $instance instanceof AppInstance) {
            return false;
        }

        $since = $instance->starting_commit;

        if (! is_string($since) || $since === '') {
            $started = $group->started_at ?? $group->tasks->first()?->started_at;
            $since = $started instanceof Carbon ? $started->toIso8601String() : null;
        }

        return is_string($since) && $since !== '' && $this->diff->hasCommitsSince($instance, $since);
    }

    /** @param list<array{id: string, kind: string, label: string, text: string, at: string}> $entries
     * @return list<array{id: string, kind: string, label: string, text: string, at: string}>
     */
    private function recentMessages(array $entries): array
    {
        $messages = array_values(array_filter($entries, static fn (array $entry): bool => $entry['kind'] === 'message'));
        $messages = array_slice($messages, -5);
        $firstAt = $messages[0]['at'] ?? '';
        $activities = array_values(array_filter($entries, static fn (array $entry): bool => $entry['kind'] === 'activity' && ($firstAt === '' || $entry['at'] >= $firstAt)));

        return array_map(static fn (array $entry): array => [...$entry, 'text' => mb_substr($entry['text'], -2000)], [...$messages, ...$activities]);
    }
}
