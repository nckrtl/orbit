<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

/**
 * Fetches a settling pull request's base ref into the workspace before a conflict fixup starts, and
 * catches the workspace up with a task branch that moved on GitHub (ADR 0164). The base fetch updates
 * the remote-tracking ref and does not change the task branch.
 */
interface TaskBaseBranchFetcher
{
    /**
     * @throws TaskPullRequestException
     */
    public function fetch(TaskGroup $group, string $base): void;

    /**
     * Fetches `origin/task-{group id}` and fast-forwards the workspace when it is strictly behind that ref,
     * before a resumed subtask starts. A workspace that is level, ahead, or diverged is left alone. Never forces.
     * When `$missingRefOk` is true, a remote ref that does not exist is not a failure and the workspace stays.
     *
     * @throws TaskPullRequestException
     */
    public function fastForward(TaskGroup $group, bool $missingRefOk = false): void;
}
