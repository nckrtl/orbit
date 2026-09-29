<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

final readonly class TaskWorkspaceName
{
    public static function for(Task $group): string
    {
        return 'task-'.$group->id;
    }
}
