<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

interface TaskPullRequestWatcher
{
    /** @return 'merged'|'closed'|'open'|null */
    public function status(TaskGroup $group): ?string;

    public function verifies(TaskGroup $group, string $commit): bool;
}
