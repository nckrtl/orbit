<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPositions;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\DB;

final readonly class DestroyTaskAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    public function execute(TaskGroup $group, Task $task): Task
    {
        $this->requireExtension->execute();

        return DB::transaction(static function () use ($group, $task): Task {
            $locked = TaskGroup::query()->lockForUpdate()->findOrFail($group->id);

            if ($locked->status !== TaskGroupStatus::Backlog) {
                throw TaskGroupGuard::notInBacklog();
            }

            $task->delete();

            /** @var list<int> $ids */
            $ids = Task::query()->where('task_group_id', $locked->id)->orderBy('position')->pluck('id')->all();
            TaskPositions::assign($locked, $ids);

            return $task;
        });
    }
}
