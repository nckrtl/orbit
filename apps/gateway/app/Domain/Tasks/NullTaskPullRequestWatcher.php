<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

final readonly class NullTaskPullRequestWatcher implements TaskPullRequestWatcher
{
    public function status(TaskGroup $group): ?string
    {
        return null;
    }

    public function health(TaskGroup $group): ?TaskPullRequestHealth
    {
        return null;
    }
}
