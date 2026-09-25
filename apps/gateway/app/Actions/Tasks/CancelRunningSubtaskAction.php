<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Models\AgentThread;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskGroup;

/**
 * Stops a running subtask's implementer and its running check, then lets the scheduler start the next
 * subtask. Both stops are remote calls that run after the status check and outside any database
 * transaction. The scheduler records the cancel afterwards, only while the subtask is still running.
 */
final readonly class CancelRunningSubtaskAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private AgentDriverRegistry $drivers,
        private TaskCheckRunner $checks,
        private TaskScheduler $scheduler,
    ) {}

    public function execute(TaskGroup $group, Task $task): Task
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();

        $this->scheduler->cancelRunningSubtask($group, $task, function (Task $running) use ($group): void {
            $this->interruptImplementer($running);
            $this->stopCheck($group, $running);
        });

        return $task->fresh() ?? $task;
    }

    private function interruptImplementer(Task $task): void
    {
        $thread = $task->implementerThread ?? AgentThread::query()
            ->where('task_id', $task->id)
            ->where('role', 'implementer')
            ->first();

        if (! $thread instanceof AgentThread) {
            return;
        }

        try {
            $this->drivers->get($thread->driver)->interrupt($thread);
        } catch (AgentDriverException $exception) {
            throw $this->stopFailed($exception->getMessage());
        }
    }

    /**
     * A subtask without an implementer is in its baseline check, and one that handed off may be in
     * its handoff check. Either check stops with the subtask. The scheduler marks it cancelled once the
     * stop succeeded, so a failed stop leaves it running.
     */
    private function stopCheck(TaskGroup $group, Task $task): void
    {
        /** @var TaskCheck|null $check */
        $check = $task->checks()->where('status', TaskCheckStatus::Running->value)->latest('id')->first();
        if (! $check instanceof TaskCheck) {
            return;
        }

        $instance = $group->fresh()?->taskable;
        if (! $instance instanceof AppInstance) {
            return;
        }

        try {
            $this->checks->cancel($instance, $check->process());
        } catch (TaskCheckException $exception) {
            throw $this->stopFailed($exception->getMessage());
        }
    }

    private function stopFailed(string $reason): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.subtask_interrupt_failed',
            message: __('The subtask remains running because its implementer or check could not be stopped: :reason', ['reason' => $reason]),
            status: 502,
        );
    }
}
