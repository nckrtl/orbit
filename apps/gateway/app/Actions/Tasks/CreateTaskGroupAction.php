<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskGroupGuard;
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
        private StartTaskPlannerAction $planner,
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

        if ($data->plan && $data->status !== TaskGroupStatus::Backlog) {
            throw TaskGroupGuard::planRequiresBacklog();
        }

        if ($data->plan && $reviewerDriver !== 't3') {
            throw TaskGroupGuard::plannerDriverUnavailable();
        }

        if ($data->status === TaskGroupStatus::Todo && $data->tasks === []) {
            throw TaskGroupGuard::noSubtasks();
        }

        $missing = array_keys(array_filter($data->tasks, static fn (TaskInputData $task): bool => $task->deliverables === []));
        if ($data->status === TaskGroupStatus::Todo && $missing !== []) {
            throw TaskGroupGuard::deliverablesMissing(array_map(static fn (int $index): string => 'position '.($index + 1).' "'.$data->tasks[$index]->title.'"', $missing));
        }

        $group = TaskGroup::query()->create([
            'app_id' => $data->appId,
            'implementer_agent_driver' => $implementerDriver,
            'reviewer_agent_driver' => $reviewerDriver,
            'title' => $data->title,
            'brief' => $data->brief,
            'status' => $data->status,
            'notify_coder' => $data->notifyCoder,
            'plan' => $data->plan,
            'implementer_model' => $this->model('implementer_model', TaskAgentDefaults::ImplementerModel),
            'reviewer_model' => $this->model('reviewer_model', TaskAgentDefaults::ReviewerModel),
        ]);

        foreach ($data->tasks as $index => $task) {
            Task::query()->create([
                'task_group_id' => $group->id,
                'position' => $index + 1,
                'title' => $task->title,
                'brief' => $task->brief,
                'deliverables' => $task->deliverables,
                'status' => TaskStatus::Todo,
            ]);
        }

        if ($data->plan) {
            $group = $this->planner->execute($group);
        }

        if ($data->status === TaskGroupStatus::Todo) {
            $this->scheduler->claimNext();
        }

        return $group->refresh()->load(['app', 'tasks', 'taskable']);
    }

    private function model(string $key, string $default): string
    {
        $model = config('orbit.tasks.'.$key);

        return is_string($model) && $model !== '' ? $model : $default;
    }
}
