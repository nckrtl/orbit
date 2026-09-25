<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskStatus;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskGroup;

final readonly class CancelTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private AppInstanceRemover $remover,
        private TaskPullRequestPublisher $publisher,
    ) {}

    public function execute(TaskGroup $group): TaskGroup
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();

        $group->refresh()->load(['app', 'tasks', 'taskable']);

        $unpublished = $group->status === TaskGroupStatus::Settling && ($group->pr_url === null || $group->pr_url === '');
        if ($group->status === TaskGroupStatus::Completed || ($group->status === TaskGroupStatus::Settling && ! $unpublished)) {
            throw new ResourceOperationException(
                errorCode: 'tasks.not_cancellable',
                message: __('A completed task group, or a settling one with a pull request, cannot be cancelled.'),
                status: 409,
            );
        }

        $instance = $group->taskable;

        if ($instance instanceof AppInstance) {
            if ($unpublished) {
                $this->pushApprovedWork($group);
            }
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
     * A settling group without a pull request can still hold approved commits that only exist in its
     * workspace. They reach the task branch on origin before the workspace is removed.
     */
    private function pushApprovedWork(TaskGroup $group): void
    {
        if (! $group->tasks->contains(static fn (Task $task): bool => $task->status === TaskStatus::Completed)) {
            return;
        }

        try {
            $this->publisher->push($group);
        } catch (TaskPullRequestException $exception) {
            throw new ResourceOperationException(
                errorCode: 'tasks.push_failed',
                message: __('The group remains settling because its approved commits could not be pushed to task-:group: :reason', ['group' => $group->id, 'reason' => $exception->getMessage()]),
                status: 502,
            );
        }
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
