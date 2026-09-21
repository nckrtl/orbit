<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

/**
 * Opens the GitHub pull request for a settling Task group.
 *
 * A no-op implementation returns null. A real opener uses `gh` in the
 * reviewer workspace or the GitHub HTTP API and returns the pull-request URL.
 */
interface TaskPullRequestOpener
{
    public function open(TaskGroup $group): ?string;
}
