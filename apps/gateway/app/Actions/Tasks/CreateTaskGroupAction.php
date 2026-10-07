<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Models\Project;
use App\Models\Task;

final readonly class CreateTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private TaskScheduler $scheduler,
        private AgentDriverRegistry $drivers,
    ) {}

    public function execute(CreateTaskGroupData $data): Task
    {
        $this->requireExtension->execute();

        // Tasks publish only through the GitHub App (/reference/github-app#read-through-the-github-cli).
        if (Project::query()->find($data->projectId)?->source_access === ProjectSourceAccess::GhCli) {
            throw new ResourceOperationException(
                'tasks.github_app_required',
                'Tasks publish through the GitHub App, and this Project reads its repository through the GitHub CLI.',
            );
        }

        try {
            $implementerDriver = $this->drivers->get($this->configuredDriver('orbit.tasks.implementer_agent_driver'))->key();
            $reviewerDriver = $this->drivers->get($this->configuredDriver('orbit.tasks.reviewer_agent_driver'))->key();
        } catch (AgentDriverException) {
            throw new ResourceOperationException('tasks.agent_driver_unavailable', 'The configured agent driver is unavailable.', 409);
        }

        if ($data->status === TaskGroupStatus::Todo && $data->tasks === []) {
            throw TaskGroupGuard::noSubtasks();
        }

        $missing = array_keys(array_filter($data->tasks, static fn (TaskInputData $task): bool => $task->deliverables === []));
        if ($data->status === TaskGroupStatus::Todo && $missing !== []) {
            throw TaskGroupGuard::deliverablesMissing(array_map(static fn (int $index): string => 'position '.($index + 1).' "'.$data->tasks[$index]->title.'"', $missing));
        }

        $group = Task::topLevel()->create([
            'project_id' => $data->projectId,
            'implementer_agent_driver' => $implementerDriver,
            'reviewer_agent_driver' => $reviewerDriver,
            'title' => $data->title,
            'brief' => $data->brief,
            'status' => $data->status,
            'notify_coder' => $data->notifyCoder,
            'preview' => $data->preview,
            'implementer_model' => $this->model('implementer_model', TaskAgentDefaults::ImplementerModel),
            'reviewer_model' => $this->model('reviewer_model', TaskAgentDefaults::ReviewerModel),
        ]);

        foreach ($data->tasks as $index => $task) {
            Task::query()->create([
                'parent_id' => $group->id,
                'position' => $index + 1,
                'title' => $task->title,
                'brief' => $task->brief,
                'deliverables' => $task->deliverables,
                'status' => TaskStatus::Todo,
            ]);
        }

        if ($data->status === TaskGroupStatus::Todo) {
            $this->scheduler->claimNext();
        }

        return $group->refresh()->load(['project', 'tasks', 'taskable']);
    }

    private function model(string $key, string $default): string
    {
        $model = config('orbit.tasks.'.$key);

        return is_string($model) && $model !== '' ? $model : $default;
    }

    private function configuredDriver(string $key): string
    {
        $driver = config($key, 'pi');

        // ADR 0190: a new task stores the pi driver only. Any other value stores no task.
        if (! is_string($driver) || $driver !== 'pi') {
            throw new AgentDriverException('The configured agent driver is unavailable.');
        }

        return $driver;
    }
}
