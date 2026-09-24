<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

interface TaskPullRequestWatcher
{
    /** @return 'merged'|'closed'|'open'|null */
    public function status(TaskGroup $group): ?string;

    /** The pull request's state and, while it is open, its conflicts and failed checks. Null when unknown. */
    public function health(TaskGroup $group): ?TaskPullRequestHealth;
}
