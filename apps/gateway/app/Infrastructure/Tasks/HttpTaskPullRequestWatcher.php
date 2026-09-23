<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Models\TaskGroup;
use Throwable;

/**
 * Reads the state of the pull request Orbit opened, through the Gateway GitHub App.
 */
final readonly class HttpTaskPullRequestWatcher implements TaskPullRequestWatcher
{
    public function __construct(private RepositoryPullRequestAccess $access, private GitHubApi $github) {}

    public function status(TaskGroup $group): ?string
    {
        $repository = GitHubRepository::fromOrigin((string) $group->app->repository_url);
        if (! $repository instanceof GitHubRepository || ! is_string($group->pr_url)) {
            return null;
        }
        $number = $repository->pullRequestNumber($group->pr_url);
        if ($number === null) {
            return null;
        }
        try {
            return $this->github->pullRequestState($this->access->token($repository), $repository, $number)->value;
        } catch (Throwable) {
            return null;
        }
    }
}
