<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/**
 * Asks Jev whether the pull request change list covers every subtask of the group, except cancelled and failed subtasks.
 */
interface TaskBriefCoverage
{
    /**
     * @param  list<string>|null  $approvalChanges
     * @return list<string> the titles of subtasks that no change covers
     *
     * @throws TaskSessionClassificationException
     */
    public function missing(Task $group, TaskRunPullRequest $pullRequest, ?int $approvalCommentId = null, ?array $approvalChanges = null): array;
}
