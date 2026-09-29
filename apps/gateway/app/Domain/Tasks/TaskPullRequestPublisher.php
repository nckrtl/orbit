<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/**
 * Pushes the task branch from the group's workspace and opens its pull request.
 * The refspec names the stored approved commit, never HEAD (ADR 0160).
 */
interface TaskPullRequestPublisher
{
    /**
     * Pushes `$commit` and opens the pull request.
     *
     * @return string the pull request's web URL
     *
     * @throws TaskPullRequestException
     */
    public function publish(Task $group, string $body, string $commit): string;

    /**
     * Pushes `$commit` to the task branch without opening a pull request.
     *
     * @throws TaskPullRequestException
     */
    public function push(Task $group, string $commit): void;
}
