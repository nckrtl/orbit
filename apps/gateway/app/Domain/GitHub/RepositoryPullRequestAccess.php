<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use Illuminate\Support\Facades\Cache;

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
        [$credentials, $installation] = $this->installation($repository);

        return $this->github->repositoryPullRequestToken($credentials, $installation, $repository);
    }

    /** A separate read-only review token. Missing access is an error, never anonymous or empty success. */
    public function reviewsToken(GitHubRepository $repository): string
    {
        [$credentials, $installation] = $this->installation($repository);

        return $this->github->repositoryReviewsToken($credentials, $installation, $repository);
    }

    /** The branch watch caches only the installation id, never its read-only token. */
    public function cachedReadToken(GitHubRepository $repository): string
    {
        $credentials = $this->store->credentials();
        if (! $credentials instanceof GitHubAppCredentials) {
            throw new GitHubApiException('The Gateway GitHub App is not registered.');
        }
        $key = 'github:pull-request-installation:'.$credentials->appId.':'.strtolower($repository->owner.'/'.$repository->name);
        $installation = Cache::get($key);
        if (! is_int($installation)) {
            $installation = $this->resolveInstallation($credentials, $repository);
            Cache::forever($key, $installation);
        }
        try {
            return $this->github->repositoryPullRequestReadToken($credentials, $installation, $repository);
        } catch (GitHubApiException $exception) {
            if (! $exception->isTokenRefusal()) {
                throw $exception;
            }
            Cache::forget($key);
            $installation = $this->resolveInstallation($credentials, $repository);
            Cache::forever($key, $installation);
            try {
                return $this->github->repositoryPullRequestReadToken($credentials, $installation, $repository);
            } catch (GitHubApiException $retry) {
                if ($retry->isTokenRefusal()) {
                    Cache::forget($key);
                }
                throw $retry;
            }
        }
    }

    /**
     * The token that reads the pull request's check runs
     * ([ADR 0140](/decisions/0140-watch-settling-pull-requests-for-conflicts-and-failed-checks)), or
     * null when GitHub does not grant it, such as for an installation that has not accepted `checks: read`.
     */
    public function checksToken(GitHubRepository $repository): ?string
    {
        try {
            [$credentials, $installation] = $this->installation($repository);

            return $this->github->repositoryChecksToken($credentials, $installation, $repository);
        } catch (GitHubApiException) {
            return null;
        }
    }

    /**
     * @return array{GitHubAppCredentials, int}
     *
     * @throws GitHubApiException
     */
    private function installation(GitHubRepository $repository): array
    {
        $credentials = $this->store->credentials();
        if (! $credentials instanceof GitHubAppCredentials) {
            throw new GitHubApiException('The Gateway GitHub App is not registered.');
        }

        return [$credentials, $this->resolveInstallation($credentials, $repository)];
    }

    private function resolveInstallation(GitHubAppCredentials $credentials, GitHubRepository $repository): int
    {
        return $this->github->repositoryInstallation($credentials, $repository)
            ?? throw new GitHubApiException('The Gateway GitHub App is not installed on '.$repository->owner.'/'.$repository->name.'.');
    }
}
