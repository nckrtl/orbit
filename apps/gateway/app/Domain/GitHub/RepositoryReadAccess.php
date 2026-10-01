<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
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
 *
 * A `gh_cli` Project reads with the token of the Gateway's GitHub CLI login instead
 * ([GitHub App](/reference/github-app#read-through-the-github-cli)). It never
 * asks the App and never falls back to a read without a credential.
 */
final readonly class RepositoryReadAccess
{
    public function __construct(
        private GitHubAppStore $store,
        private GitHubApi $github,
        private GitHubCliToken $cli,
    ) {}

    public function for(#[SensitiveParameter] string $origin, ProjectSourceAccess $source): GitReadEnvironment
    {
        $repository = GitHubRepository::fromOrigin($origin);

        if ($source === ProjectSourceAccess::GhCli) {
            if (! $repository instanceof GitHubRepository) {
                throw new ResourceOperationException(
                    errorCode: 'project.source_access_invalid',
                    message: 'GitHub CLI source access needs a github.com repository URL.',
                );
            }

            return GitReadEnvironment::forGitHubToken($this->cli->token());
        }

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
