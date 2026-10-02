<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final readonly class TaskSessionObserver
{
    public function __construct(private AgentThreadObserver $threads, private TaskWorkspaceDiffReader $diff) {}

    /**
     * Observes the task's implementer and its reviewer. Only the thread that acts in the task's current
     * phase defers the task while it works: the implementer while the task runs, and that subtask's
     * reviewer while it is in review, in a consult, or relaying a direction. An earlier subtask's reviewer does not.
     */
    public function observe(Task $group, Task $task): TaskSessionObservation
    {
        $group->loadMissing(['project', 'tasks', 'taskable']);
        $records = AgentThread::query()->where('task_group_id', $group->id)->orderBy('id')->get();
        $reviewerId = $this->subtaskReviewerId($records, $group, $task);
        $observations = [];
        $actingRole = $task->status === TaskStatus::Reviewing || $this->reviewerIsActing($task) ? TaskThreadRole::Reviewer : TaskThreadRole::Implementer;
        $actingThreadWorks = false;
        foreach ($records as $thread) {
            if (str_starts_with($thread->external_id, TaskAgentSpawner::PendingPrefix)) {
                continue;
            }
            $role = TaskThreadRole::tryFrom($thread->role);
            if ($role === null || ! $this->includeThread($thread, $role, $task, $group, $reviewerId)) {
                continue;
            }
            $observation = $this->threads->observe($thread);
            $actingThreadWorks = $actingThreadWorks || ($role === $actingRole && $this->acts($thread, $role, $task, $group, $reviewerId) && $observation?->state === AgentThreadState::Working);
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
                sessionUpdatedAt: $observation?->sessionUpdatedAt,
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

    /** @param Collection<int, AgentThread> $records */
    private function subtaskReviewerId(Collection $records, Task $group, Task $task): ?int
    {
        $fallback = null;
        foreach ($records as $thread) {
            if ($thread->role !== TaskThreadRole::Reviewer->value || $thread->task_id !== $task->id || str_starts_with($thread->external_id, TaskAgentSpawner::PendingPrefix)) {
                continue;
            }
            $fallback = $thread->id;
            if ($thread->id === $group->reviewer_agent_thread_id) {
                return $thread->id;
            }
        }

        return $fallback;
    }

    private function includeThread(AgentThread $thread, TaskThreadRole $role, Task $task, Task $group, ?int $reviewerId): bool
    {
        if ($role === TaskThreadRole::Implementer) {
            return $thread->task_id === $task->id;
        }
        if ($reviewerId !== null) {
            return $thread->id === $reviewerId;
        }

        return $thread->task_id === null && $thread->id === $group->reviewer_agent_thread_id;
    }

    /** During a consult or a direction relay, the reviewer acts while the subtask stays running. */
    private function reviewerIsActing(Task $task): bool
    {
        return $task->status === TaskStatus::Running
            && ($task->consult_comment_id !== null || $task->direction_relay_comment_id !== null);
    }

    /** The acting reviewer is this subtask's reviewer, or a legacy shared reviewer after notification. */
    private function acts(AgentThread $thread, TaskThreadRole $role, Task $task, Task $group, ?int $reviewerId): bool
    {
        if ($role === TaskThreadRole::Implementer) {
            return $thread->task_id === $task->id;
        }
        if ($reviewerId !== null) {
            return $thread->id === $reviewerId;
        }

        return $thread->id === $group->reviewer_agent_thread_id
            && $task->review_notified_attempt === $task->review_attempt;
    }

    private function hasNewCommits(Task $group): bool
    {
        $instance = $group->taskable;

        if (! $instance instanceof Instance) {
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
