<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\TaskGroupMetricsRefresher;
use App\Models\TaskGroup;

final readonly class ShowTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private TaskGroupMetricsRefresher $metrics,
    ) {}

    public function execute(TaskGroup $group): TaskGroup
    {
        $this->requireExtension->execute();

        $group->loadMissing(['app', 'tasks', 'taskable']);

        if (! $group->status->isActive()) {
            return $group;
        }

        return $this->metrics->refresh($group);
    }
}
