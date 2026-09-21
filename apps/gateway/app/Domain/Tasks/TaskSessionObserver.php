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

    public function observe(TaskGroup $group): TaskSessionObservation
    {
        $group->loadMissing(['app', 'tasks', 'taskable']);
        $current = $group->tasks->first(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true));
        $wanted = array_filter([$group->reviewer_agent_thread_id, $current?->implementer_agent_thread_id]);
        $threads = [];
        $hasNewCommits = $this->hasNewCommits($group);
        foreach (AgentThread::query()->where('task_group_id', $group->id)->whereIn('id', $wanted)->orderBy('id')->get() as $thread) {
            $role = TaskThreadRole::tryFrom($thread->role);
            if ($role === null || ($role === TaskThreadRole::Reviewer && ($thread->id !== $group->reviewer_agent_thread_id || $thread->task_id !== null)) || ($role === TaskThreadRole::Implementer && ($thread->id !== $current?->implementer_agent_thread_id || $thread->task_id !== $current->id))) {
                continue;
            }
            $observation = $this->threads->observe($thread);
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
                idle: $observation?->state === AgentThreadState::Idle,
                pendingApprovalId: $approval, pendingUserInputId: $question,
                lastAssistantText: $observation?->lastText('assistant'), lastUserText: $observation?->lastText('user'),
                hasNewCommitsSinceThreadStart: $hasNewCommits, prUrl: $group->pr_url, ciSummary: null,
                available: $observation !== null && $observation->state !== null,
                error: $observation?->error, inputRequests: $requests,
            );
        }

        return new TaskSessionObservation(
            groupId: $group->id, groupStatus: $group->status->value, title: $group->title, brief: $group->brief,
            hasPendingSubtasks: $group->tasks->contains(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Pending, TaskStatus::Reserved, TaskStatus::Running, TaskStatus::Reviewing], true)),
            prUrl: $group->pr_url, ciSummary: null, threads: $threads,
            available: count($threads) === ($current === null ? 1 : 2) && ! array_any($threads, static fn (TaskThreadObservation $thread): bool => ! $thread->available),
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
}
