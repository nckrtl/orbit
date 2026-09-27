<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskGroup;

/**
 * The commit a subtask review and its base run both diff from.
 * A recorded start commit wins. Continuations inherit their source subtask's base; other tasks use the previous approved commit, then the workspace starting commit.
 */
final class TaskReviewBase
{
    public static function commit(Task $task): string
    {
        $recorded = $task->subtask_start_commit;
        if (self::isCommit($recorded)) {
            return $recorded;
        }
        if ($task->continuation_of_task_id !== null) {
            $source = Task::query()->find($task->continuation_of_task_id);
            if ($source instanceof Task) {
                $sourceStart = self::commit($source);
                if ($sourceStart !== '') {
                    return $sourceStart;
                }
            }
        }
        $task->loadMissing('taskGroup.taskable');
        $group = $task->taskGroup;
        $previous = self::previousApprovedCommit($group, $task);
        if ($previous !== null) {
            return $previous;
        }
        $instance = $group->taskable;
        $starting = $instance instanceof AppInstance ? $instance->starting_commit : null;

        return self::isCommit($starting) ? $starting : '';
    }

    private static function previousApprovedCommit(TaskGroup $group, Task $task): ?string
    {
        $earlier = Task::query()
            ->where('task_group_id', $group->id)
            ->where(function ($query) use ($task): void {
                $query->where('position', '<', $task->position)
                    ->orWhere(function ($query) use ($task): void {
                        $query->where('position', $task->position)->where('id', '<', $task->id);
                    });
            })
            ->pluck('id');
        if ($earlier->isEmpty()) {
            return null;
        }
        $commit = TaskComment::query()
            ->whereIn('task_id', $earlier)
            ->where('type', TaskCommentType::Approved->value)
            ->whereNotNull('commit_sha')
            ->latest('id')
            ->value('commit_sha');

        return self::isCommit($commit) ? $commit : null;
    }

    /** @phpstan-assert-if-true string $value */
    private static function isCommit(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{7,64}\z/i', $value) === 1;
    }
}
