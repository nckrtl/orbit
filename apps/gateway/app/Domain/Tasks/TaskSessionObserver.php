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

    public function observe(TaskGroup $group, Task $task): TaskSessionObservation
    {
        $group->loadMissing(['app', 'tasks', 'taskable']);
        $observations = [];
        $hasActiveThread = false;
        foreach (AgentThread::query()->where('task_group_id', $group->id)->orderBy('id')->get() as $thread) {
            $role = TaskThreadRole::tryFrom($thread->role);
            $belongsToTask = $thread->task_id === $task->id;
            $sharedReviewer = $task->status === TaskStatus::Reviewing
                && $thread->task_id === null && $role === TaskThreadRole::Reviewer;
            if ($role === null || (! $belongsToTask && ! $sharedReviewer)) {
                continue;
            }
            $observation = $this->threads->observe($thread);
            $hasActiveThread = $hasActiveThread || $observation?->state === AgentThreadState::Working;
            $observations[] = [$thread, $role, $observation];
        }
        if ($hasActiveThread) {
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
            );
        }

        return new TaskSessionObservation(
            taskId: $task->id, taskStatus: $task->status->value, taskTitle: $task->title, taskBrief: $task->brief,
            groupId: $group->id, groupStatus: $group->status->value, title: $group->title, brief: $group->brief,
            hasPendingSubtasks: $group->tasks->contains(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Pending, TaskStatus::Reserved, TaskStatus::Running, TaskStatus::Reviewing], true)),
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
}
