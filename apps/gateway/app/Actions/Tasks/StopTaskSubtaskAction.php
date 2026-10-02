<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Task;

/** Stops remote work before cancellation. Call outside the cancellation transaction. */
final readonly class StopTaskSubtaskAction
{
    public function __construct(
        private AgentDriverRegistry $drivers,
        private TaskCheckRunner $checks,
    ) {}

    /** Identity of the work about to be stopped, for completion's locked revalidation. */
    public function snapshot(Task $group, Task $task): string
    {
        $fresh = $task->fresh() ?? $task;
        $parent = $group->fresh() ?? $group;
        $thread = in_array($fresh->status, [TaskStatus::Running, TaskStatus::Reviewing], true)
            ? $this->actingThread($parent, $fresh) : null;

        return json_encode([
            'status' => $fresh->status->value,
            'instance_id' => $parent->taskable_id,
            'thread' => $thread?->only(['id', 'driver', 'runtime_key', 'external_id']),
            'checks' => $fresh->checks()->where('status', TaskCheckStatus::Running->value)->orderBy('id')
                ->get(['id', 'pid', 'process_started', 'head_before', 'tree_before'])->toArray(),
        ], JSON_THROW_ON_ERROR);
    }

    public function execute(Task $group, Task $task): void
    {
        if (! in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true)) {
            return;
        }

        $group = $group->fresh() ?? $group;
        $thread = $this->actingThread($group, $task);
        if ($thread instanceof AgentThread) {
            try {
                $this->drivers->get($thread->driver)->interrupt($thread);
            } catch (AgentDriverException $exception) {
                throw $this->stopFailed($exception->getMessage());
            }
        }

        $instance = $group->fresh()?->taskable;
        foreach ($task->checks()->where('status', TaskCheckStatus::Running->value)->orderBy('id')->get() as $check) {
            if ($check->pid < 1 || trim($check->process_started) === '') {
                throw $this->stopFailed('The running check has no recorded process identity; its start may have been interrupted.');
            }
            if (! $instance instanceof Instance) {
                return;
            }
            try {
                $this->checks->cancel($instance, $check->process());
            } catch (TaskCheckException $exception) {
                throw $this->stopFailed($exception->getMessage());
            }
        }
    }

    private function actingThread(Task $group, Task $task): ?AgentThread
    {
        if ($task->status === TaskStatus::Running) {
            return $task->implementerThread ?? AgentThread::query()
                ->where('task_id', $task->id)->where('role', 'implementer')->first();
        }

        $reviewers = AgentThread::query()->where('task_group_id', $group->id)->where('task_id', $task->id)
            ->where('role', 'reviewer');

        return (clone $reviewers)->whereKey($group->reviewer_agent_thread_id)->first() ?? $reviewers->latest('id')->first();
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
