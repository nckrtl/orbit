<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskAssistanceData;
use App\Data\Tasks\TasksStatusData;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\TaskGroup;

final readonly class ShowTasksStatusAction
{
    public function __construct(private TaskExtensionState $extension) {}

    public function execute(): TasksStatusData
    {
        $assistance = array_values(TaskGroup::query()
            ->with('app')
            ->where('assistance_requested', true)
            ->orderBy('id')
            ->get()
            ->map(static fn (TaskGroup $group): TaskAssistanceData => TaskAssistanceData::fromModel($group))
            ->all());

        return new TasksStatusData(
            enabled: $this->extension->enabled(),
            assistance: $assistance,
        );
    }
}
