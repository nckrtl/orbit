<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskGroup;

/**
 * Fills Task and TaskGroup settle metrics from agent observations and the
 * shared checkout. A refused agent read keeps the last stored thread values.
 */
final readonly class TaskGroupMetricsRefresher
{
    public function __construct(
        private AgentThreadObserver $threads,
        private TaskWorkspaceDiffReader $diff,
    ) {}

    public function refresh(TaskGroup $group): TaskGroup
    {
        $group->loadMissing(['app', 'tasks', 'taskable']);

        if (! $group->status->isActive()) {
            return $group;
        }

        $reviewer = $group->reviewerThread;
        if ($reviewer !== null) {
            $this->threads->observe($reviewer);
        }
        $reviewerTokens = $reviewer?->tokens;
        $taskTokens = 0;
        $hasTaskTokens = false;

        foreach ($group->tasks as $task) {
            $this->refreshTask($task);

            if ($task->tokens !== null) {
                $hasTaskTokens = true;
                $taskTokens += max(0, $task->tokens);
            }
        }

        if ($hasTaskTokens || $reviewerTokens !== null) {
            $group->tokens = $taskTokens + max(0, $reviewerTokens ?? 0);
        }

        $instance = $group->taskable;
        $base = $group->app->default_branch;

        if ($instance instanceof AppInstance && is_string($base) && $base !== '') {
            $changes = $this->diff->lineChanges($instance, $base);
            if ($changes !== null) {
                $group->lines_added = $changes['additions'];
                $group->lines_deleted = $changes['deletions'];
                $group->line_diff = $changes['additions'] + $changes['deletions'];
            }
        }

        $started = $group->started_at;

        if ($started !== null) {
            $group->duration_ms = max(0, (int) now()->diffInMilliseconds($started, true));
        }

        if ($group->isDirty()) {
            $group->save();
        }

        return $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
    }

    private function refreshTask(Task $task): void
    {
        $thread = $task->implementerThread;
        if ($thread !== null) {
            $this->threads->observe($thread);
            if ($thread->tokens !== null) {
                $task->tokens = $thread->tokens;
            }
            if ($thread->lines_added !== null && $thread->lines_deleted !== null) {
                $task->lines_added = $thread->lines_added;
                $task->lines_deleted = $thread->lines_deleted;
                $task->line_diff = $thread->lines_added + $thread->lines_deleted;
            }
        }

        $started = $task->started_at;

        if ($started !== null) {
            $ended = $task->settled_at ?? now();
            $task->duration_ms = max(0, (int) $ended->diffInMilliseconds($started, true));
        }

        if ($task->isDirty()) {
            $task->save();
        }
    }
}
