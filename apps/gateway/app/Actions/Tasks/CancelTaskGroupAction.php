<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\AppInstance;
use App\Models\TaskGroup;

final readonly class CancelTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private AppInstanceRemover $remover,
    ) {}

    public function execute(TaskGroup $group): TaskGroup
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();

        $group->refresh()->load(['app', 'tasks', 'taskable']);

        if (in_array($group->status, [TaskGroupStatus::Settling, TaskGroupStatus::Completed], true)) {
            throw new ResourceOperationException(
                errorCode: 'tasks.not_cancellable',
                message: __('A settling or completed task group cannot be cancelled.'),
                status: 409,
            );
        }

        $instance = $group->taskable;

        if ($instance instanceof AppInstance) {
            $this->removeWorkspace($instance);
        }

        $group->taskable()->dissociate();
        $group->status = TaskGroupStatus::Cancelled;
        $group->assistance_requested = false;
        $group->save();

        $group->tasks()
            ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])
            ->update(['status' => TaskStatus::Cancelled, 'settled_at' => now()]);
        $group->tasks()->where('assistance_requested', true)->update(['assistance_requested' => false]);

        return $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
    }

    /**
     * Removal also deletes a never-active workspace's checkout from its Node. When removal refuses
     * before it starts, for example on a half-created checkout or an unreachable Node, cancel still
     * finishes: it deletes the record and leaves the checkout for Doctor to report.
     */
    private function removeWorkspace(AppInstance $instance): void
    {
        try {
            $this->remover->execute($instance, true);
        } catch (ResourceOperationException $exception) {
            $instance->refresh();

            if ($instance->status !== AppInstanceState::SourceResolved || $instance->routes()->exists()) {
                throw $exception;
            }

            $instance->delete();
        }
    }
}
