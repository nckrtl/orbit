<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\TaskScheduler;
use App\Models\Task;

/**
 * Cancels a todo subtask directly, or stops a running subtask's implementer and running check before
 * letting the scheduler start the next subtask. Remote stops run after the status check and outside any
 * database transaction. The scheduler records a running cancel only while the subtask is still running.
 */
final readonly class CancelRunningSubtaskAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private StopTaskSubtaskAction $stop,
        private TaskScheduler $scheduler,
    ) {}

    public function execute(Task $group, Task $task): Task
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();

        $this->scheduler->cancelRunningSubtask($group, $task, function (Task $running) use ($group): void {
            $this->stop->execute($group, $running);
        });

        return $task->fresh() ?? $task;
    }
}
