<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\UpdateTaskGroupData;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Models\AppInstance;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private TaskScheduler $scheduler,
        private TaskWorkspaceSigner $signer,
    ) {}

    public function execute(TaskGroup $group, UpdateTaskGroupData $data): TaskGroup
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();
        $this->commitPlan($group, $data);

        // The row lock makes a status move and a scheduler claim exclusive: whichever commits second sees the other's status.
        $updated = DB::transaction(static function () use ($group, $data): TaskGroup {
            $locked = TaskGroup::query()->with('tasks')->lockForUpdate()->findOrFail($group->id);

            if (($data->title !== null || $data->brief !== null) && $locked->status !== TaskGroupStatus::Backlog) {
                throw TaskGroupGuard::notInBacklog();
            }

            if ($data->status !== null && $data->status !== $locked->status) {
                if (! in_array($locked->status, [TaskGroupStatus::Backlog, TaskGroupStatus::Todo], true)) {
                    throw TaskGroupGuard::alreadyClaimed();
                }

                if ($data->status === TaskGroupStatus::Todo && $locked->tasks->isEmpty()) {
                    throw TaskGroupGuard::noSubtasks();
                }

                $locked->status = $data->status;
            }

            $locked->title = $data->title ?? $locked->title;
            $locked->brief = $data->brief ?? $locked->brief;
            $locked->save();

            return $locked;
        });

        if ($data->status === TaskGroupStatus::Todo) {
            $this->scheduler->claimNext();
        }

        return $updated->fresh(['app', 'tasks', 'taskable']) ?? $updated;
    }

    /**
     * ADR 0124: a planning group moving to Todo commits the planner's ADRs and documentation first. A failed
     * commit leaves the group in Backlog.
     */
    private function commitPlan(TaskGroup $group, UpdateTaskGroupData $data): void
    {
        $group->refresh()->load(['tasks', 'taskable']);
        $instance = $group->taskable;

        if (! $group->plan || $data->status !== TaskGroupStatus::Todo || $group->status !== TaskGroupStatus::Backlog
            || $group->tasks->isEmpty() || ! $instance instanceof AppInstance) {
            return;
        }

        if ($this->signer->commit($instance, 'Plan: '.($data->title ?? $group->title)) === null) {
            throw TaskGroupGuard::planCommitFailed();
        }
    }
}
