<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use Closure;

/** The completion receipt is authoritative even without an ended-PR assistance reason. */
final class TaskExecutionHold
{
    /** Use the already locked, fresh parent at database transitions. */
    public static function active(Task $group): bool
    {
        return in_array($group->watched_pr_completion, ['merged', 'closed'], true);
    }

    /**
     * Refresh under the admission lock and hold it through the remote call and its recorded result.
     * A completion authorization waits for admitted work, then prevents every subsequent admission.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T|null
     */
    public static function run(Task $group, Closure $operation): mixed
    {
        return app(TaskExecutionLock::class)->synchronized($group->id, static function () use ($group, $operation): mixed {
            $fresh = Task::topLevel()->find($group->id);
            if (! $fresh instanceof Task || self::active($fresh)
                || in_array($fresh->status, [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled], true)) {
                return null;
            }

            return $operation();
        });
    }
}
