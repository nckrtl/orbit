<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

interface TaskPullRequestUpdater
{
    public function updateBranch(Task $group, string $headSha): TaskBranchUpdate;
}
