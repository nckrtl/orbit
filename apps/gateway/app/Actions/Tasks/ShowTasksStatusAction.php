<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskAssistanceData;
use App\Data\Tasks\TaskMergeData;
use App\Data\Tasks\TasksStatusData;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskTickClock;
use App\Models\Task;

final readonly class ShowTasksStatusAction
{
    public function __construct(private TaskExtensionState $extension, private TaskTickClock $clock) {}

    public function execute(): TasksStatusData
    {
        $assistance = array_values(Task::topLevel()
            ->with('project')
            ->where('assistance_requested', true)
            ->orderBy('id')
            ->get()
            ->map(static fn (Task $group): TaskAssistanceData => TaskAssistanceData::fromModel($group))
            ->all());

        $merges = array_values(Task::topLevel()
            ->with('project')
            ->where('execution_mode', TaskExecutionMode::Managed)
            ->whereNotIn('status', [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled, TaskGroupStatus::Failed])
            ->where(static fn ($query) => $query->whereHas('project', static fn ($project) => $project->where('review_and_merge', true))->orWhereNotNull('pr_branch'))
            ->orderBy('id')
            ->get()
            ->map(static fn (Task $group): TaskMergeData => TaskMergeData::fromModel($group))
            ->all());

        return new TasksStatusData(
            enabled: $this->extension->enabled(),
            assistance: $assistance,
            lastTickAt: $this->clock->lastStartedAt(),
            merges: $merges,
        );
    }
}
