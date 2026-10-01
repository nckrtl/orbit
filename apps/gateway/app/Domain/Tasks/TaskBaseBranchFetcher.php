<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/**
 * Fetches remote-tracking refs before a turn and prepares a resumed workspace (ADR 0164).
 */
interface TaskBaseBranchFetcher
{
    /**
     * @throws TaskPullRequestException
     */
    public function fetch(Task $group, string $base): void;

    /**
     * Uses the already-fetched `origin/task-{group id}` and fast-forwards a strictly behind workspace
     * before a resumed subtask starts. A level, ahead, or diverged workspace is left alone. Never forces.
     * This step does not fetch or use a token. When `$missingRefOk` is true, an absent ref is not a failure.
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
