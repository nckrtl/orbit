<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskFinalReview;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskStatus;
use App\Models\Instance;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CancelTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private RemoveTaskWorkspaceAction $workspace,
        private TaskPullRequestPublisher $publisher,
        private NodeReachabilityProbe $reachability,
    ) {}

    public function execute(Task $group): Task
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();

        $group->refresh()->load(['project', 'tasks', 'taskable']);

        $unpublished = in_array($group->status, TaskGroupStatus::awaitingCompletion(), true) && ($group->pr_url === null || $group->pr_url === '');
        if ($group->status === TaskGroupStatus::Completed || (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true) && ! $unpublished)) {
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
        // An unreachable Node cannot accept a push or a removal. Cancel still ends the group and keeps the Instance,
        // so the sweep can delete the checkout later. A Node that answers keeps the fail-closed path below.
        $offlineId = $instance instanceof Instance && $this->nodeUnreachable($instance) ? $instance->id : null;

        if ($instance instanceof Instance && $offlineId === null) {
            if ($unpublished) {
                $this->pushApprovedWork($group);
            }
            $this->removeWorkspace($group, $instance);
        }

        $removedId = $instance instanceof Instance && $offlineId === null ? $instance->id : null;
        $attachedByClaim = DB::transaction(static function () use ($group, $removedId, $offlineId): ?Instance {
            $locked = Task::topLevel()->with('taskable')->lockForUpdate()->findOrFail($group->id);
            // A claim can attach an Instance between the checks above and this lock. Cancel removes whatever is still
            // attached and was not removed above, whether or not it saw a claim in flight. The unreachable Instance
            // stays attached so the sweep can find the checkout.
            $current = $locked->taskable instanceof Instance ? $locked->taskable : null;
            $keepOffline = $current instanceof Instance && $current->id === $offlineId;
            $attached = $current instanceof Instance && $current->id !== $removedId && ! $keepOffline ? $current : null;
            if ($keepOffline) {
                if (! ($locked->assistance_requested && $locked->assistance_kind === AssistanceKind::Direction)) {
                    $locked->fill(TaskAssistance::attributes(AssistanceKind::Failure, null, RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The Node is unreachable.'));
                }
            } else {
                $locked->taskable()->dissociate();
                $locked->assistance_requested = false;
            }
            $locked->status = TaskGroupStatus::Cancelled;
            $locked->save();

            return $attached;
        });

        if ($attachedByClaim instanceof Instance) {
            try {
                $this->removeWorkspace($group, $attachedByClaim);
            } catch (Throwable $exception) {
                $locked = Task::topLevel()->findOrFail($group->id);
                $locked->taskable()->associate($attachedByClaim);
                $locked->save();
                $this->workspace->recordFailure($locked, $exception);

                throw $exception;
            }
        }

        $group->tasks()
            ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])
            ->update([
                'status' => TaskStatus::Cancelled,
                'settled_at' => now(),
                'assistance_requested' => false,
            ]);
        $cancelled = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        // Removal success clears the assistance flag and keeps the last reason. A cancelled task keeps the reason and does not ask.
        if (! $cancelled->assistance_requested) {
            $group->tasks()->where('assistance_requested', true)->update(['assistance_requested' => false]);
            $cancelled = $group->fresh(['project', 'tasks', 'taskable']) ?? $cancelled;
        }

        return $cancelled;
    }

    /** The probe is the one check that separates a Node that is gone from a Node that answered and refused. */
    private function nodeUnreachable(Instance $instance): bool
    {
        $instance->loadMissing('node');

        return $this->reachability->degradation($instance->node) === ExporterDegradationReason::Unreachable;
    }

    /** A refused removal keeps the checkout and the Instance row, asks for assistance, and returns the error. */
    private function removeWorkspace(Task $group, Instance $instance): void
    {
        try {
            $this->workspace->remove($instance, $group);
        } catch (Throwable $exception) {
            $this->workspace->recordFailure($group, $exception);

            throw $exception;
        }
    }

    /**
     * A settling group without a pull request can still hold approved commits that only exist in its
     * workspace. They reach the task branch on origin before the workspace is removed.
     */
    private function pushApprovedWork(Task $group): void
    {
        if (! $group->tasks->contains(static fn (Task $task): bool => $task->status === TaskStatus::Completed)) {
            return;
        }
        $commit = TaskFinalReview::cancelPushCommit($group);
        if ($commit === null) {
            return;
        }

        try {
            $this->publisher->push($group, $commit);
        } catch (TaskPullRequestException $exception) {
            throw new ResourceOperationException(
                errorCode: 'tasks.push_failed',
                message: __('The group remains settling because its approved commits could not be pushed to task-:group: :reason', ['group' => $group->id, 'reason' => $exception->getMessage()]),
                status: 502,
            );
        }
    }
}
