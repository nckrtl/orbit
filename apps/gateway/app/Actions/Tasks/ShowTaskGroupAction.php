<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\TaskGroup;

final readonly class ShowTaskGroupAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    public function execute(TaskGroup $group): TaskGroup
    {
        $this->requireExtension->execute();

        $group->loadMissing(['app', 'tasks', 'taskable']);

        return $group;
    }
}
