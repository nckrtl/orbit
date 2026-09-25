<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentThread;
use App\Models\Task;
use App\Models\TaskGroup;

final readonly class CancelRunningSubtaskAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private AgentDriverRegistry $drivers,
        private TaskScheduler $scheduler,
    ) {}

    public function execute(TaskGroup $group, Task $task): Task
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();

        $current = Task::query()->where('task_group_id', $group->id)->findOrFail($task->id);
        if ($current->status !== TaskStatus::Running) {
            throw new ResourceOperationException(
                errorCode: 'tasks.subtask_not_running',
                message: __('Only a running subtask can be cancelled.'),
                status: 409,
            );
        }

        $thread = $current->implementerThread ?? AgentThread::query()
            ->where('task_id', $current->id)
            ->where('role', 'implementer')
            ->first();

        if ($thread instanceof AgentThread) {
            try {
                $this->drivers->get($thread->driver)->interrupt($thread);
            } catch (AgentDriverException $exception) {
                throw new ResourceOperationException(
                    errorCode: 'tasks.subtask_interrupt_failed',
                    message: __('The subtask remains running because its implementer could not be stopped: :reason', ['reason' => $exception->getMessage()]),
                    status: 502,
                );
            }
        }

        $this->scheduler->cancelRunningSubtask($group, $current);

        return $task->fresh() ?? $task;
    }
}
