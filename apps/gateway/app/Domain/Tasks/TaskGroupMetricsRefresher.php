<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;

/**
 * Fills Task and TaskGroup settle metrics from T3 thread snapshots and the
 * shared checkout. A refused T3 read keeps the last stored thread values.
 */
final readonly class TaskGroupMetricsRefresher
{
    public function __construct(
        private T3ThreadReader $threads,
        private TaskWorkspaceDiffReader $diff,
    ) {}

    public function refresh(TaskGroup $group): TaskGroup
    {
        $group->loadMissing(['app', 'tasks', 'taskable']);

        if (! $group->status->isActive()) {
            return $group;
        }

        $node = $this->node($group);
        $reviewerTokens = $this->threadTokens($node, $group->reviewer_thread_id);
        $taskTokens = 0;
        $hasTaskTokens = false;

        foreach ($group->tasks as $task) {
            $this->refreshTask($task, $node);

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

    private function refreshTask(Task $task, ?Node $node): void
    {
        $metrics = $this->threadMetrics($node, $task->implementer_thread_id);

        if ($metrics instanceof T3ThreadMetrics) {
            $task->tokens = $metrics->tokens;
            $task->line_diff = $metrics->lineDiff;
            if ($metrics->linesAdded !== null && $metrics->linesDeleted !== null) {
                $task->lines_added = $metrics->linesAdded;
                $task->lines_deleted = $metrics->linesDeleted;
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

    private function threadTokens(?Node $node, ?string $threadId): ?int
    {
        $metrics = $this->threadMetrics($node, $threadId);

        return $metrics instanceof T3ThreadMetrics ? $metrics->tokens : null;
    }

    private function threadMetrics(?Node $node, ?string $threadId): ?T3ThreadMetrics
    {
        if (! $node instanceof Node || ! is_string($threadId) || $threadId === '') {
            return null;
        }

        $snapshot = $this->threads->snapshot($node, $threadId);

        return is_array($snapshot) ? T3ThreadMetrics::fromSnapshot($snapshot) : null;
    }

    private function node(TaskGroup $group): ?Node
    {
        $instance = $group->taskable;

        if (! $instance instanceof AppInstance) {
            return null;
        }

        $instance->loadMissing('node');

        return $instance->node;
    }
}
