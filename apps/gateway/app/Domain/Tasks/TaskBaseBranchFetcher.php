<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

/**
 * Fetches a settling pull request's base ref into the workspace before a conflict fixup starts
 * (ADR 0164). The fetch updates the remote-tracking ref and does not change the task branch.
 */
interface TaskBaseBranchFetcher
{
    /**
     * @throws TaskPullRequestException
     */
    public function fetch(TaskGroup $group, string $base): void;
}
