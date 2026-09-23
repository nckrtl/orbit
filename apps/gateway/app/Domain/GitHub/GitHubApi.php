<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use SensitiveParameter;

interface GitHubApi
{
    /**
     * Exchange the one-time code of an App manifest registration for the App's credentials.
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

    /**
     * Opens the pull request, or returns the open one that already has this head.
     *
     * @return string the pull request's web URL
     *
     * @throws GitHubApiException
     */
    public function openPullRequest(#[SensitiveParameter] string $token, GitHubRepository $repository, GitHubPullRequestDraft $draft): string;

    /** @throws GitHubApiException */
    public function pullRequestState(#[SensitiveParameter] string $token, GitHubRepository $repository, int $number): GitHubPullRequestState;
}
