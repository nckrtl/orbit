<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\UpdateTaskData;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPositions;
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

            if ($locked->status !== TaskGroupStatus::Backlog) {
                throw TaskGroupGuard::notInBacklog();
            }

            $task->title = $data->title ?? $task->title;
            $task->brief = $data->brief ?? $task->brief;
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
