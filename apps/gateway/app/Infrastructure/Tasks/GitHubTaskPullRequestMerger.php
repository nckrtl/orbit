<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubMergeResult;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReviewEvent;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\GitHub\RequiredCheckState;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestMerger;
use App\Models\Project;
use App\Models\Task;

/**
 * Reviews and merges through the Project's App installation (ADR 0203). The write token is the publishing
 * token: `contents`, `pull_requests`, and `workflows` write. Check runs use a `checks: read` token, and the
 * pull request list uses the cached `pull_requests: read` token. No new App permission is needed.
 */
final readonly class GitHubTaskPullRequestMerger implements TaskPullRequestMerger
{
    public function __construct(
        private RepositoryPullRequestAccess $access,
        private GitHubApi $github,
    ) {}

    public function requiredCheck(Task $group, string $sha, string $checkName): RequiredCheckState
    {
        $repository = $this->repository($group->project);
        if (! $repository instanceof GitHubRepository) {
            return RequiredCheckState::Unreadable;
        }
        $token = $this->access->checksToken($repository);
        if ($token === null) {
            return RequiredCheckState::Unreadable;
        }

        try {
            return RequiredCheckState::of($this->github->checkRuns($token, $repository, $sha, $checkName), $sha, $checkName);
        } catch (GitHubApiException) {
            return RequiredCheckState::Unreadable;
        }
    }

    public function baseTipGreenAhead(Task $group, string $base, string $headSha, string $checkName): bool
    {
        $repository = $this->repository($group->project);
        if (! $repository instanceof GitHubRepository) {
            return false;
        }

        try {
            $token = $this->access->readToken($repository);
            $tip = array_first($this->github->branchCommits($token, $repository, $base))?->sha;
            // The merge base is the tip itself when the head already contains it.
            if (! is_string($tip) || $this->github->compareCommits($token, $repository, $headSha, $tip)->mergeBaseSha === $tip) {
                return false;
            }
        } catch (GitHubApiException) {
            return false;
        }

        return $this->requiredCheck($group, $tip, $checkName) === RequiredCheckState::Passed;
    }

    public function merge(Task $group, string $sha): GitHubMergeResult
    {
        [$repository, $number] = $this->pullRequest($group);

        try {
            return $this->github->mergePullRequest($this->access->token($repository), $repository, $number, $sha);
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The pull request could not be merged: '.$exception->getMessage(), previous: $exception);
        }
    }

    public function review(Task $group, string $sha, GitHubReviewEvent $event, string $body): int
    {
        [$repository, $number] = $this->pullRequest($group);

        try {
            return $this->github->submitReview($this->access->token($repository), $repository, $number, $sha, $event, $body);
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The pull request review could not be submitted: '.$exception->getMessage(), previous: $exception);
        }
    }

    public function openPullRequests(Project $project): array
    {
        $repository = $this->repository($project);
        if (! $repository instanceof GitHubRepository) {
            throw new TaskPullRequestException('The Project repository is not on github.com.');
        }

        try {
            return $this->github->openPullRequests($this->access->cachedReadToken($repository), $repository);
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The open pull requests could not be listed: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return array{GitHubRepository, int}
     *
     * @throws TaskPullRequestException
     */
    private function pullRequest(Task $group): array
    {
        $repository = $this->repository($group->project);
        $number = $repository instanceof GitHubRepository && is_string($group->pr_url) ? $repository->pullRequestNumber($group->pr_url) : null;
        if (! $repository instanceof GitHubRepository || $number === null) {
            throw new TaskPullRequestException('The task has no pull request on its Project repository.');
        }

        return [$repository, $number];
    }

    private function repository(Project $project): ?GitHubRepository
    {
        return GitHubRepository::fromOrigin((string) $project->repository_url);
    }
}
