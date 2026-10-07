<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\UpdateTaskGroupData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private TaskScheduler $scheduler,
        private TaskExecutionLock $execution,
    ) {}

    public function execute(Task $group, UpdateTaskGroupData $data): Task
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();
        if ($data->status === TaskGroupStatus::Todo && $group->status === TaskGroupStatus::Backlog) {
            self::requireDeliverables($group->tasks()->get());
        }
        // The row lock makes a status move and a scheduler claim exclusive: whichever commits second sees the other's status.
        $updated = $this->execution->synchronized($group->id, fn (): Task => DB::transaction(static function () use ($group, $data): Task {
            $locked = Task::topLevel()->with('tasks')->lockForUpdate()->findOrFail($group->id);

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

                if ($data->status === TaskGroupStatus::Todo) {
                    self::requireDeliverables($locked->tasks);
                }

                $locked->status = $data->status;
                if ($locked->assistance_kind !== AssistanceKind::Direction && TaskScheduler::isClaimFailureReason($locked->assistance_reason)) {
                    $locked->fill(TaskAssistance::cleared());
                }
            }

            if ($data->preview !== null) {
                if (in_array($locked->status, [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled, TaskGroupStatus::Failed], true)) {
                    throw new ResourceOperationException('tasks.preview_closed', 'Preview cannot change after a task group has ended.', 409);
                }
                $locked->preview = $data->preview;
            }
            $locked->title = $data->title ?? $locked->title;
            $locked->brief = $data->brief ?? $locked->brief;
            $locked->save();

            return $locked;
        }));

        if ($data->status === TaskGroupStatus::Todo) {
            $this->scheduler->claimNext();
        }

        return $updated->fresh(['project', 'tasks', 'taskable']) ?? $updated;
    }

    /**
     * ADR 0133: a group moves to Todo only when every subtask has deliverables.
     *
     * @param  Collection<int, Task>  $tasks
     */
    private static function requireDeliverables(Collection $tasks): void
    {
        $missing = $tasks->sortBy('position')->filter(static fn (Task $task): bool => $task->deliverableList() === []);
        if ($missing->isNotEmpty()) {
            throw TaskGroupGuard::deliverablesMissing(array_values($missing->map(static fn (Task $task): string => '#'.$task->id.' "'.$task->title.'"')->all()));
        }
    }
}
