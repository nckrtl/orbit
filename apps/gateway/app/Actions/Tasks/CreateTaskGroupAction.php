<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\CreateTaskGroupData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
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
        private AgentDriverRegistry $drivers,
    ) {}

    public function execute(CreateTaskGroupData $data): TaskGroup
    {
        $this->requireExtension->execute();

        try {
            $implementerDriver = $this->drivers->get((string) config('orbit.tasks.implementer_agent_driver', 't3'))->key();
            $reviewerDriver = $this->drivers->get((string) config('orbit.tasks.reviewer_agent_driver', 't3'))->key();
        } catch (AgentDriverException) {
            throw new ResourceOperationException('tasks.agent_driver_unavailable', 'The configured agent driver is unavailable.', 409);
        }

        $group = TaskGroup::query()->create([
            'app_id' => $data->appId,
            'implementer_agent_driver' => $implementerDriver,
            'reviewer_agent_driver' => $reviewerDriver,
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
