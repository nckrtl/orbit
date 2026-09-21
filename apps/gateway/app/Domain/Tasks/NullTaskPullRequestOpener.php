<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

final readonly class NullTaskPullRequestOpener implements TaskPullRequestOpener
{
    public function open(TaskGroup $group): ?string
    {
        return null;
    }
}
