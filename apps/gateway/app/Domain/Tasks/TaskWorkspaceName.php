<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

final readonly class TaskWorkspaceName
{
    public static function for(TaskGroup $group): string
    {
        return 'task-'.$group->id;
    }
}
