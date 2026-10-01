<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

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
    public function fetch(Task $group, string $base): void;

    /**
     * Fetches `origin/task-{group id}` and fast-forwards the workspace when it is strictly behind that ref,
     * before a resumed subtask starts. A workspace that is level, ahead, or diverged is left alone. Never forces.
     * When `$missingRefOk` is true, a remote ref that does not exist is not a failure and the workspace stays.
     *
     * @throws TaskPullRequestException
     */
    public function fastForward(Task $group, bool $missingRefOk = false): void;

    /**
     * Fetches the Project default branch, `task-{group id}`, and the pull request base when it differs,
     * before an agent turn. Uses the read token and `--no-tags`. A missing task branch is not a failure.
     * Updates remote-tracking refs only and does not move HEAD.
     *
     * @throws TaskPullRequestException
     */
    public function fetchForTurn(Task $group): void;
}
