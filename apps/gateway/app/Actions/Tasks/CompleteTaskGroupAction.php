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

    /**
     * Marks a settling group completed and removes its workspace.
     *
     * A manual complete still ends the group when removal fails, keeps the Instance, and reports the failure
     * on the completed group. Merge cleanup passes `$finishWhenRemovalFails` false so a failed removal leaves
     * the group settling for the sweep.
     */
    public function execute(TaskGroup $group, bool $finishWhenRemovalFails = true): TaskGroup
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();

        $group->loadMissing(['app', 'tasks', 'taskable']);

        if ($group->status === TaskGroupStatus::Completed) {
            $this->removeOrFinish($group, $finishWhenRemovalFails);

            return $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
        }

        if ($group->status !== TaskGroupStatus::Settling) {
            throw new ResourceOperationException(
                errorCode: 'tasks.not_settling',
                message: __('The task group is not ready to complete.'),
                status: 409,
            );
        }

        if (! $this->removeOrFinish($group, $finishWhenRemovalFails)) {
            $group->refresh();
            $group->status = TaskGroupStatus::Completed;
            $group->settled_at ??= now();
            $group->save();

            return $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
        }

        $group->refresh();
        $group->taskable()->dissociate();
        $group->status = TaskGroupStatus::Completed;
        $group->settled_at ??= now();
        $group->save();

        return $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
    }

    /**
     * Removes the workspace. A manual complete that cannot remove it still finishes and returns false.
     * Merge cleanup rethrows so the group stays settling. A repeated complete retries at once.
     */
    private function removeOrFinish(TaskGroup $group, bool $finishWhenRemovalFails): bool
    {
        try {
            $this->removeWorkspace($group);
        } catch (Throwable $exception) {
            if (! $finishWhenRemovalFails) {
                throw $exception;
            }

            return false;
        }

        return true;
    }

    /** Deletes the checkout. A refusal keeps the checkout and the Instance row, asks for assistance, and rethrows. */
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
