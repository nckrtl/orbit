<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Instance;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CompleteTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private RemoveTaskWorkspaceAction $workspace,
        private RepositoryPullRequestAccess $access,
        private GitHubApi $github,
        private StopTaskSubtaskAction $stop,
        private TaskExecutionLock $execution,
    ) {}

    /**
     * Completes a settling group, or a running/reviewing group whose watched PR ended, and removes its workspace.
     *
     * A manual complete still ends the group when removal fails, keeps the Instance, and reports the failure
     * on the completed group. Merge cleanup passes `$finishWhenRemovalFails` false so a failed removal leaves
     * the group settling for the sweep.
     */
    public function execute(Task $group, bool $finishWhenRemovalFails = true): Task
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();

        $group->refresh()->load(['project', 'tasks', 'taskable']);

        if ($group->status === TaskGroupStatus::Completed) {
            $this->removeOrFinish($group, $finishWhenRemovalFails);

            return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        }

        if (in_array($group->status, [TaskGroupStatus::Running, TaskGroupStatus::Reviewing], true)) {
            $this->authorizeEndedPullRequest($group);
            $this->completeOpenSubtasks($group);

            $this->removeOrFinish($group, $finishWhenRemovalFails);

            return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        }

        if ($group->status !== TaskGroupStatus::Settling) {
            throw $this->notReady();
        }

        if (! $this->removeOrFinish($group, $finishWhenRemovalFails)) {
            $group->refresh();
            $group->status = TaskGroupStatus::Completed;
            $group->settled_at ??= now();
            $group->save();

            return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        }

        $group->refresh();
        $group->taskable()->dissociate();
        $group->status = TaskGroupStatus::Completed;
        $group->settled_at ??= now();
        $group->save();

        return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
    }

    /** Stores authorization separately, so a crash or rollback resumes without another GitHub read. */
    private function authorizeEndedPullRequest(Task $group): void
    {
        if (in_array($group->watched_pr_completion, ['merged', 'closed'], true)) {
            return;
        }
        $url = $group->watched_pr_url;
        $repository = GitHubRepository::fromOrigin($group->project->repository_url ?? '');
        $number = $repository !== null && is_string($url) ? $repository->pullRequestNumber($url) : null;
        if ($repository === null || $number === null) {
            throw $this->notReady();
        }
        try {
            $state = $this->github->pullRequest($this->access->cachedReadToken($repository), $repository, $number)->state->value;
        } catch (Throwable) {
            throw $this->notReady();
        }
        if (! in_array($state, ['merged', 'closed'], true)) {
            throw $this->notReady();
        }

        $this->execution->synchronized($group->id, function () use ($group, $url, $state): void {
            DB::transaction(function () use ($group, $url, $state): void {
                $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
                if (! in_array($locked->status, [TaskGroupStatus::Running, TaskGroupStatus::Reviewing], true)
                    || $locked->watched_pr_url !== $url) {
                    throw $this->notReady();
                }
                if ($locked->watched_pr_completion === null) {
                    $locked->update(['watched_pr_completion' => $state]);
                }
            });
        });
        $group->refresh();
    }

    /** Remote stops precede the transaction; no subtask is ended unless the parent can end with it. */
    private function completeOpenSubtasks(Task $group): void
    {
        $this->execution->synchronized($group->id, function () use ($group): void {
            $open = [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing];
            do {
                $stopped = [];
                foreach ($group->tasks()->whereIn('status', $open)->orderBy('id')->get() as $task) {
                    $stopped[$task->id] = $this->stop->snapshot($group, $task);
                    $this->stop->execute($group, $task);
                }

                $completed = DB::transaction(function () use ($group, $open, $stopped): bool {
                    $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
                    if ($locked->status === TaskGroupStatus::Completed) {
                        return true;
                    }
                    if (! in_array($locked->status, [TaskGroupStatus::Running, TaskGroupStatus::Reviewing], true)
                        || ! in_array($locked->watched_pr_completion, ['merged', 'closed'], true)) {
                        throw $this->notReady();
                    }
                    $tasks = $locked->tasks()->whereIn('status', $open)->orderBy('id')->lockForUpdate()->get();
                    $current = [];
                    foreach ($tasks as $task) {
                        $current[$task->id] = $this->stop->snapshot($locked, $task);
                    }
                    if ($current !== $stopped) {
                        return false;
                    }
                    foreach ($tasks as $task) {
                        $task->checks()->where('status', TaskCheckStatus::Running)
                            ->update(['status' => TaskCheckStatus::Cancelled, 'finished_at' => now()]);
                        $task->update([
                            'status' => TaskStatus::Cancelled,
                            'settled_at' => now(),
                            'completion_summary' => 'Cancelled by operator.',
                            'assistance_requested' => false,
                        ]);
                    }
                    $locked->update([
                        'status' => TaskGroupStatus::Completed,
                        'settled_at' => $locked->settled_at ?? now(),
                        'assistance_requested' => false,
                    ]);

                    return true;
                });
            } while (! $completed);
        });
        $group->refresh()->load(['project', 'tasks', 'taskable']);
    }

    private function notReady(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.not_settling',
            message: __('The task group is not ready to complete.'),
            status: 409,
        );
    }

    /**
     * Removes the workspace. A manual complete that cannot remove it still finishes and returns false.
     * Merge cleanup rethrows so the group stays settling. A repeated complete retries at once.
     */
    private function removeOrFinish(Task $group, bool $finishWhenRemovalFails): bool
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
    private function removeWorkspace(Task $group): void
    {
        $instanceId = $group->taskable_id;

        try {
            $this->workspace->execute($group);
        } catch (Throwable $exception) {
            $this->workspace->recordFailure($group, $exception);

            throw $exception;
        }

        $this->workspace->clearFailure($group);

        if ($instanceId !== null && ! Instance::query()->whereKey($instanceId)->exists()) {
            $group->refresh();
            $group->taskable()->dissociate();
            $group->save();
        }
    }
}
