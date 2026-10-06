<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Models\Instance;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;

/**
 * Stops a running Project check. The next tick gives the implementer its reminder
 * ([ADR 0125](/decisions/0125-run-the-project-check-when-the-implementer-hands-off)).
 */
final readonly class CancelTaskCheckAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private TaskCheckRunner $checks,
        private TaskBroadcasts $broadcasts,
    ) {}

    public function execute(Task $group, Task $task): TaskCheck
    {
        $this->requireExtension->execute();
        $check = $task->checks()->where('status', TaskCheckStatus::Running->value)->latest('id')->first();
        $instance = $group->taskable;
        if (! $check instanceof TaskCheck || ! $instance instanceof Instance) {
            throw new ResourceOperationException(
                errorCode: 'tasks.check_not_running',
                message: __('The task has no running check.'),
                status: 409,
            );
        }
        if ($check->kind === TaskCheckKind::Probe && $check->pid === 0) {
            throw new ResourceOperationException(errorCode: 'tasks.probe_start_pending',
                message: 'The probe start must recover its process identity before cancellation.', status: 409);
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
        $this->broadcasts->groupChanged($group->id);
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
