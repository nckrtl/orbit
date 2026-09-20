<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskGroup;

final readonly class LocalTaskSettleMetricsCollector implements TaskSettleMetricsCollector
{
    public function __construct(private TaskWorkspaceDiffReader $diff) {}

    public function collect(TaskGroup $group): TaskSettleMetrics
    {
        $group->loadMissing(['app', 'tasks', 'taskable']);

        $tokens = $group->tasks->sum(
            static fn (Task $task): int => max(0, (int) ($task->tokens ?? 0)),
        );

        $lineDiff = 0;
        $instance = $group->taskable;
        $base = $group->app->default_branch;

        if ($instance instanceof AppInstance && is_string($base) && $base !== '') {
            $lineDiff = max(0, $this->diff->lineDiff($instance, $base));
        }

        $durationMs = 0;
        $started = $group->started_at;

        if ($started !== null) {
            $durationMs = max(0, (int) now()->diffInMilliseconds($started, true));
        }

        return new TaskSettleMetrics(
            tokens: $tokens,
            lineDiff: $lineDiff,
            durationMs: $durationMs,
        );
    }
}
