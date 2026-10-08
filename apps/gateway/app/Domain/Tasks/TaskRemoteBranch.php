<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\SourceControl\GitBranchName;
use App\Models\Task;

/**
 * The branch on `origin` that a task fetches and pushes. It is `task-{id}`, or the incoming pull request's
 * head branch (ADR 0203). The workspace's local branch stays `task-{id}` either way.
 */
final readonly class TaskRemoteBranch
{
    /** @throws TaskPullRequestException when a stored pull request branch is not a valid branch name */
    public static function for(Task $group): string
    {
        $branch = $group->pr_branch;
        if (! is_string($branch) || $branch === '') {
            return 'task-'.$group->id;
        }
        if (! GitBranchName::isValid($branch)) {
            throw new TaskPullRequestException('The pull request branch is not a valid Git branch name.');
        }

        return $branch;
    }
}
