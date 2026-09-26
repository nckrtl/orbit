<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

/**
 * Asks Jev whether the pull request change list covers every subtask of the group, except cancelled and failed subtasks.
 */
interface TaskBriefCoverage
{
    /**
     * @return list<string> the titles of subtasks that no change covers
     *
     * @throws TaskSessionClassificationException
     */
    public function missing(TaskGroup $group, TaskRunPullRequest $pullRequest): array;
}
