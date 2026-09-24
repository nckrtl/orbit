<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Tasks\TaskPullRequestHealth;
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
        $target = $this->target($group);
        if ($target === null) {
            return null;
        }
        [$repository, $number] = $target;
        try {
            return $this->github->pullRequest($this->access->token($repository), $repository, $number)->state->value;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Check runs need the separate `checks: read` token. Without it, only conflicts are reported.
     */
    public function health(TaskGroup $group): ?TaskPullRequestHealth
    {
        $target = $this->target($group);
        if ($target === null) {
            return null;
        }
        [$repository, $number] = $target;
        try {
            $pullRequest = $this->github->pullRequest($this->access->token($repository), $repository, $number);
            if ($pullRequest->state !== GitHubPullRequestState::Open) {
                return new TaskPullRequestHealth($pullRequest->state->value);
            }
            $failed = $this->failedChecks($repository, $pullRequest);
        } catch (Throwable) {
            return null;
        }

        $problems = [];
        if ($pullRequest->conflicts()) {
            $base = $pullRequest->baseRef ?? 'the base branch';
            $problems[] = 'It conflicts with '.$base.'; merge '.$base.' into the task branch and push.';
        }
        foreach ($failed as $run) {
            $problems[] = 'Check '.$run->name.' failed'.($run->url !== null ? ': '.$run->url : '').'.';
        }

        return new TaskPullRequestHealth('open', $problems);
    }

    /** @return list<GitHubCheckRun> */
    private function failedChecks(GitHubRepository $repository, GitHubPullRequest $pullRequest): array
    {
        if ($pullRequest->headSha === null) {
            return [];
        }
        $token = $this->access->checksToken($repository);
        if ($token === null) {
            return [];
        }

        return array_values(array_filter(
            $this->github->checkRuns($token, $repository, $pullRequest->headSha),
            static fn (GitHubCheckRun $run): bool => $run->failed(),
        ));
    }

    /** @return array{GitHubRepository, int}|null */
    private function target(TaskGroup $group): ?array
    {
        $repository = GitHubRepository::fromOrigin((string) $group->app->repository_url);
        if (! $repository instanceof GitHubRepository || ! is_string($group->pr_url)) {
            return null;
        }
        $number = $repository->pullRequestNumber($group->pr_url);

        return $number === null ? null : [$repository, $number];
    }
}
