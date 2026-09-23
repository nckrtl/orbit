<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\UpdateTaskData;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPositions;
use App\Domain\Tasks\TaskStatus;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class UpdateTaskAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    public function execute(TaskGroup $group, Task $task, UpdateTaskData $data): Task
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();

        return DB::transaction(static function () use ($group, $task, $data): Task {
            $locked = TaskGroup::query()->lockForUpdate()->findOrFail($group->id);
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $backlog = $locked->status === TaskGroupStatus::Backlog;

            if (! $backlog && ($data->title !== null || $data->brief !== null || $data->position !== null)) {
                throw TaskGroupGuard::notInBacklog();
            }

            // ADR 0133: the deliverables of a todo subtask change in any group status, but never to none outside backlog.
            if (! $backlog && $data->deliverables !== null) {
                if ($task->status !== TaskStatus::Todo) {
                    throw TaskGroupGuard::deliverablesLocked();
                }
                if ($data->deliverables === []) {
                    throw TaskGroupGuard::deliverablesRequired();
                }
            }

            if (! $backlog && $data->deliverables === null) {
                throw TaskGroupGuard::notInBacklog();
            }

            $task->title = $data->title ?? $task->title;
            $task->brief = $data->brief ?? $task->brief;
            $task->deliverables = $data->deliverables ?? $task->deliverables;
            $task->save();

            if ($data->position !== null && $data->position !== $task->position) {
                /** @var list<int> $ids */
                $ids = Task::query()->where('task_group_id', $locked->id)->whereKeyNot($task->id)->orderBy('position')->pluck('id')->all();

                if ($data->position > count($ids) + 1) {
                    throw ValidationException::withMessages(['position' => [__('The position must be between 1 and :count.', ['count' => count($ids) + 1])]]);
                }

                array_splice($ids, $data->position - 1, 0, [$task->id]);
                TaskPositions::assign($locked, $ids);
            }

            return $task->refresh();
        });
    }
}
