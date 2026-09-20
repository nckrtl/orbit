<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use SensitiveParameter;
use Throwable;

/**
 * Supplies the Git configuration that lets one command read one repository through the Gateway's
 * GitHub App ([ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app)).
 *
 * The configuration travels in `GIT_CONFIG_*` environment variables, so the token is never part of
 * the origin URL, the command arguments, `.git/config`, or a file. A repository that no installation
 * covers, and a repository on another host, gets no configuration and is read as before. A GitHub
 * failure, and an unreadable stored credential, also yield no configuration, so a public repository
 * stays readable and a private one fails with the read's own error code.
 */
final readonly class RepositoryReadAccess
{
    public function __construct(
        private GitHubAppStore $store,
        private GitHubApi $github,
    ) {}

    public function for(#[SensitiveParameter] string $origin): GitReadEnvironment
    {
        $repository = GitHubRepository::fromOrigin($origin);

        if (! $repository instanceof GitHubRepository) {
            return GitReadEnvironment::none();
        }

        try {
            $credentials = $this->store->credentials();

            if (! $credentials instanceof GitHubAppCredentials) {
                return GitReadEnvironment::none();
            }

            $installation = $this->github->repositoryInstallation($credentials, $repository);

            if ($installation === null) {
                return GitReadEnvironment::none();
            }

            return GitReadEnvironment::forGitHubToken(
                $this->github->repositoryReadToken($credentials, $installation, $repository),
            );
        } catch (Throwable) {
            return GitReadEnvironment::none();
        }
    }
}
