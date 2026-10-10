<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use App\Domain\Tasks\TaskBranchUpdate;
use SensitiveParameter;

interface GitHubApi
{
    /**
     * Exchange the one-time code of a Project manifest registration for the App's credentials.
     *
     * @throws GitHubApiException
     */
    public function convertManifest(#[SensitiveParameter] string $code): GitHubAppCredentials;

    /**
     * @return list<GitHubInstallation>
     *
     * @throws GitHubApiException
     */
    public function installations(GitHubAppCredentials $credentials): array;

    /**
     * The installation that covers the repository, or null when no installation covers it.
     *
     * @throws GitHubApiException
     */
    public function repositoryInstallation(GitHubAppCredentials $credentials, GitHubRepository $repository): ?int;

    /**
     * A token that reads the contents of this one repository and expires after one hour.
     *
     * @throws GitHubApiException
     */
    public function repositoryReadToken(
        GitHubAppCredentials $credentials,
        int $installationId,
        GitHubRepository $repository,
    ): string;

    public function repositoryPullRequestToken(
        GitHubAppCredentials $credentials,
        int $installationId,
        GitHubRepository $repository,
    ): string;

    /** @throws GitHubApiException */
    public function repositoryPullRequestReadToken(
        GitHubAppCredentials $credentials,
        int $installationId,
        GitHubRepository $repository,
    ): string;

    /**
     * One page of pull requests in GitHub's default order, in any state, with this branch as head.
     *
     * @return list<GitHubBranchPullRequest>
     *
     * @throws GitHubApiException
     */
    public function pullRequestsByHead(#[SensitiveParameter] string $token, GitHubRepository $repository, string $head): array;

    /**
     * A token that only reads the check runs of this one repository, and expires after one hour.
     * It is separate from the pull request token because GitHub refuses a whole token request that
     * names a permission the installation has not accepted.
     *
     * @throws GitHubApiException
     */
    public function repositoryChecksToken(
        GitHubAppCredentials $credentials,
        int $installationId,
        GitHubRepository $repository,
    ): string;

    /**
     * A token that only reads pull requests/reviews in this one repository.
     *
     * @throws GitHubApiException
     */
    public function repositoryReviewsToken(
        GitHubAppCredentials $credentials,
        int $installationId,
        GitHubRepository $repository,
    ): string;

    /**
     * Complete review records, at most 10 pages of 100; malformed/incomplete/overflow reads fail.
     *
     * @return list<GitHubReview>
     *
     * @throws GitHubApiException
     * @throws GitHubReviewOverflowException
     */
    public function reviews(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number): array;

    /** @throws GitHubApiException */
    public function review(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, int $reviewId): GitHubReview;

    /**
     * Complete selected-review comments, at most 5 pages of 100. No author/reply filtering here.
     *
     * @return list<GitHubReviewComment>
     *
     * @throws GitHubApiException
     * @throws GitHubReviewOverflowException
     */
    public function reviewComments(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, int $reviewId): array;

    /**
     * Opens the pull request, or returns the open one that already has this head.
     *
     * @throws GitHubApiException
     */
    public function openPullRequest(#[SensitiveParameter] string $token, GitHubRepository $repository, GitHubPullRequestDraft $draft): GitHubOpenedPullRequest;

    /**
     * Requests reviewers on an open pull request. Re-requesting the same logins is safe.
     *
     * @param  list<string>  $reviewers
     *
     * @throws GitHubApiException
     */
    public function requestPullRequestReviewers(
        #[SensitiveParameter] string $token,
        GitHubRepository $repository,
        int $number,
        array $reviewers,
    ): void;

    /** Merge the base into exactly the observed head, without rebasing or force-pushing. */
    public function updatePullRequestBranch(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, string $headSha): TaskBranchUpdate;

    /** @throws GitHubApiException */
    public function pullRequest(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number): GitHubPullRequest;

    /**
     * The latest check run of each check on the commit, every page up to 1,000 runs. With a check
     * name, only runs of that exact name. A longer list, or one that changes or ends before its
     * `total_count`, fails.
     *
     * @return list<GitHubCheckRun>
     *
     * @throws GitHubApiException
     */
    public function checkRuns(#[SensitiveParameter] string $token, GitHubRepository $repository, string $sha, ?string $checkName = null): array;

    /**
     * The first 100 commits reachable from the branch head, newest first, in GitHub's history order.
     * Needs a token with `contents: read`.
     *
     * @return list<GitHubCommit>
     *
     * @throws GitHubApiException
     */
    public function branchCommits(#[SensitiveParameter] string $token, GitHubRepository $repository, string $branch): array;

    /**
     * How the head commit relates to the base commit. Needs a token with `contents: read`.
     *
     * @throws GitHubApiException
     */
    public function compareCommits(#[SensitiveParameter] string $token, GitHubRepository $repository, string $baseSha, string $headSha): GitHubCommitComparison;

    /**
     * Open pull requests of the repository, oldest first, at most three pages of 100. A malformed row fails the list.
     *
     * @return list<GitHubListedPullRequest>
     *
     * @throws GitHubApiException
     */
    public function openPullRequests(#[SensitiveParameter] string $token, GitHubRepository $repository): array;

    /**
     * Submits a review decision for exactly `$commitId` and returns the review id. Needs `pull_requests: write`.
     *
     * @throws GitHubApiException
     */
    public function submitReview(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, string $commitId, GitHubReviewEvent $event, string $body): int;

    /**
     * Merges the pull request with a merge commit, only while its head is `$sha`. A refusal is a result, not an exception.
     *
     * @throws GitHubApiException when GitHub cannot be reached
     */
    public function mergePullRequest(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number, string $sha): GitHubMergeResult;
}
