<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskStatus;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\DB;

final readonly class CancelTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private RemoveTaskWorkspaceAction $workspace,
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

        // A live claim owns the workspace it is provisioning. It removes that workspace once it finds the group
        // cancelled, so cancel leaves it alone and only removes what the claim attached before the cancel landed.
        $claimInFlight = $this->workspace->claimInFlight($group) && $group->taskable_id === null;
        $instance = $claimInFlight ? null : $this->workspace->find($group);

        if ($instance instanceof AppInstance) {
            if ($unpublished) {
                $this->pushApprovedWork($group);
            }
            $this->workspace->remove($instance);
        }

        $removedId = $instance?->id;
        $attachedByClaim = DB::transaction(static function () use ($group, $removedId): ?AppInstance {
            $locked = TaskGroup::query()->with('taskable')->lockForUpdate()->findOrFail($group->id);
            // A claim can attach an Instance between the checks above and this lock. Cancel removes whatever is still
            // attached and was not removed above, whether or not it saw a claim in flight.
            $attached = $locked->taskable instanceof AppInstance && $locked->taskable_id !== $removedId ? $locked->taskable : null;
            $locked->taskable()->dissociate();
            $locked->status = TaskGroupStatus::Cancelled;
            $locked->assistance_requested = false;
            $locked->save();

            return $attached;
        });

        if ($attachedByClaim instanceof AppInstance) {
            $this->workspace->remove($attachedByClaim);
        }

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
}
