<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

/**
 * Pushes the task branch from the group's workspace and opens its pull request.
 */
interface TaskPullRequestPublisher
{
    /**
     * @return string the pull request's web URL
     *
     * @throws TaskPullRequestException
     */
    public function publish(TaskGroup $group, string $body): string;

    /**
     * Pushes the workspace HEAD to the task branch without opening a pull request.
     *
     * @throws TaskPullRequestException
     */
    public function push(TaskGroup $group): void;
}
