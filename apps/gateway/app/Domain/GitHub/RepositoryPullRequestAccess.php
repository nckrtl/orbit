<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/**
 * Mints the token that pushes a task branch and opens or watches its pull request
 * ([ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt)). Unlike a read, a publish has
 * no anonymous fallback, so a missing App or installation is an error.
 */
final readonly class RepositoryPullRequestAccess
{
    public function __construct(
        private GitHubAppStore $store,
        private GitHubApi $github,
    ) {}

    /** @throws GitHubApiException */
    public function token(GitHubRepository $repository): string
    {
        $credentials = $this->store->credentials();
        if (! $credentials instanceof GitHubAppCredentials) {
            throw new GitHubApiException('The Gateway GitHub App is not registered.');
        }
        $installation = $this->github->repositoryInstallation($credentials, $repository);
        if ($installation === null) {
            throw new GitHubApiException('The Gateway GitHub App is not installed on '.$repository->owner.'/'.$repository->name.'.');
        }

        return $this->github->repositoryPullRequestToken($credentials, $installation, $repository);
    }
}
