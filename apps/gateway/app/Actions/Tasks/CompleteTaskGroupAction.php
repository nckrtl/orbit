<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\AppInstance;
use App\Models\TaskGroup;
use Throwable;

final readonly class CompleteTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private RemoveTaskWorkspaceAction $workspace,
    ) {}

    public function execute(TaskGroup $group): TaskGroup
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();

        $group->loadMissing(['app', 'tasks', 'taskable']);

        if ($group->status === TaskGroupStatus::Completed) {
            $this->removeWorkspace($group);

            return $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
        }

        if ($group->status !== TaskGroupStatus::Settling) {
            throw new ResourceOperationException(
                errorCode: 'tasks.not_settling',
                message: __('The task group is not ready to complete.'),
                status: 409,
            );
        }

        $this->removeWorkspace($group);

        $group->refresh();
        $group->taskable()->dissociate();
        $group->status = TaskGroupStatus::Completed;
        $group->settled_at ??= now();
        $group->save();

        return $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
    }

    /**
     * Deletes the checkout before the group is reported complete. A refusal keeps the checkout and the
     * Instance row, asks for assistance, and returns the error. A repeated complete retries at once.
     */
    private function removeWorkspace(TaskGroup $group): void
    {
        $instanceId = $group->taskable_id;

        try {
            $this->workspace->execute($group);
        } catch (Throwable $exception) {
            $this->workspace->recordFailure($group, $exception);

            throw $exception;
        }

        $this->workspace->clearFailure($group);

        if ($instanceId !== null && ! AppInstance::query()->whereKey($instanceId)->exists()) {
            $group->refresh();
            $group->taskable()->dissociate();
            $group->save();
        }
    }
}
