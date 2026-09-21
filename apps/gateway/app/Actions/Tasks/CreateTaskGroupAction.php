<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\CreateTaskGroupData;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Models\Task;
use App\Models\TaskGroup;

final readonly class CreateTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private TaskScheduler $scheduler,
    ) {}

    public function execute(CreateTaskGroupData $data): TaskGroup
    {
        $this->requireExtension->execute();

        $group = TaskGroup::query()->create([
            'app_id' => $data->appId,
            'agent_driver' => config('orbit.tasks.agent_driver', 't3'),
            'title' => $data->title,
            'brief' => $data->brief,
            'status' => TaskGroupStatus::Queued,
            'notify_coder' => $data->notifyCoder,
            'implementer_model' => TaskAgentDefaults::ImplementerModel,
            'reviewer_model' => TaskAgentDefaults::ReviewerModel,
        ]);

        foreach ($data->tasks as $index => $task) {
            Task::query()->create([
                'task_group_id' => $group->id,
                'position' => $index + 1,
                'title' => $task->title,
                'brief' => $task->brief,
                'status' => TaskStatus::Pending,
            ]);
        }

        $this->scheduler->claimNext();

        return $group->refresh()->load(['app', 'tasks', 'taskable']);
    }
}
