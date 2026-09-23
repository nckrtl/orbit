<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskGroup;

/**
 * Stops a running Project check. The next tick gives the implementer its reminder
 * ([ADR 0124](/decisions/0124-run-the-project-check-when-the-implementer-hands-off)).
 */
final readonly class CancelTaskCheckAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private TaskCheckRunner $checks,
    ) {}

    public function execute(TaskGroup $group, Task $task): TaskCheck
    {
        $this->requireExtension->execute();
        /** @var TaskCheck|null $check */
        $check = $task->checks()->where('status', TaskCheckStatus::Running->value)->latest('id')->first();
        $instance = $group->taskable;
        if (! $check instanceof TaskCheck || ! $instance instanceof AppInstance) {
            throw new ResourceOperationException(
                errorCode: 'tasks.check_not_running',
                message: __('The task has no running check.'),
                status: 409,
            );
        }
        $claimed = TaskCheck::query()->whereKey($check->id)->where('status', TaskCheckStatus::Running->value)
            ->update(['status' => TaskCheckStatus::Cancelled->value, 'finished_at' => now()]);
        if ($claimed === 0) {
            throw new ResourceOperationException(
                errorCode: 'tasks.check_not_running',
                message: __('The task has no running check.'),
                status: 409,
            );
        }
        try {
            $this->checks->cancel($instance, $check->process());
        } catch (TaskCheckException $exception) {
            throw new ResourceOperationException(
                errorCode: 'tasks.check_unreachable',
                message: __('The check was marked cancelled, but its process could not be stopped: :reason', ['reason' => $exception->getMessage()]),
                status: 502,
            );
        }

        return $check->refresh();
    }
}
