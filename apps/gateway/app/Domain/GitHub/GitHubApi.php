<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

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

    /**
     * A token that pushes to this one repository and opens its pull requests, and expires after one hour.
     *
     * @throws GitHubApiException
     */
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
     * @return string the pull request's web URL
     *
     * @throws GitHubApiException
     */
    public function openPullRequest(#[SensitiveParameter] string $token, GitHubRepository $repository, GitHubPullRequestDraft $draft): string;

    /** @throws GitHubApiException */
    public function pullRequest(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number): GitHubPullRequest;

    /**
     * The latest check run of each check on the commit, up to 100 runs.
     *
     * @return list<GitHubCheckRun>
     *
     * @throws GitHubApiException
     */
    public function checkRuns(#[SensitiveParameter] string $token, GitHubRepository $repository, string $sha): array;
}
