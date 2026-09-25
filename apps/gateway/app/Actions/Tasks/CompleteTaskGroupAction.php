<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\TaskGroup;

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
            return $group;
        }

        if ($group->status !== TaskGroupStatus::Settling) {
            throw new ResourceOperationException(
                errorCode: 'tasks.not_settling',
                message: __('The task group is not ready to complete.'),
                status: 409,
            );
        }

        $this->workspace->execute($group);

        $group->taskable()->dissociate();
        $group->status = TaskGroupStatus::Completed;
        $group->settled_at ??= now();
        $group->save();

        return $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
    }
}
