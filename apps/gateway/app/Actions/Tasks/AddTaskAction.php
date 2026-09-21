<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\AddTaskData;
use App\Domain\Tasks\TaskStatus;
use App\Models\Task;
use App\Models\TaskGroup;

final readonly class AddTaskAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    public function execute(TaskGroup $group, AddTaskData $data): Task
    {
        $this->requireExtension->execute();

        $position = ((int) $group->tasks()->max('position')) + 1;

        return Task::query()->create([
            'task_group_id' => $group->id,
            'position' => $position,
            'title' => $data->title,
            'brief' => $data->brief,
            'status' => TaskStatus::Pending,
        ]);
    }
}
