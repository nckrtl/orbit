<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

final readonly class NullTaskPullRequestWatcher implements TaskPullRequestWatcher
{
    public function status(Task $group): ?string
    {
        return null;
    }

    public function health(Task $group): ?TaskPullRequestHealth
    {
        return null;
    }
}
