<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\BranchHeadReader;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCommit;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\SourceControl\GitBranchName;

/**
 * Reads a branch head through the Gateway GitHub App: the installation, one `contents: read` token,
 * and the first page of the branch's commits. It costs the same reads as a resolver answer for a
 * branch whose head is deployed.
 */
final readonly class GitHubBranchHeadReader implements BranchHeadReader
{
    public function __construct(private GitHubAppStore $store, private GitHubApi $github) {}

    public function head(GitHubRepository $repository, string $branch): string
    {
        GitBranchName::validate($branch);
        $credentials = $this->store->credentials();

        if (! $credentials instanceof GitHubAppCredentials) {
            throw new GitHubApiException('The Gateway GitHub App is not registered.');
        }

        $installation = $this->github->repositoryInstallation($credentials, $repository)
            ?? throw new GitHubApiException('The Gateway GitHub App is not installed on '.$repository->owner.'/'.$repository->name.'.');
        $token = $this->github->repositoryReadToken($credentials, $installation, $repository);
        $head = $this->github->branchCommits($token, $repository, $branch)[0] ?? null;

        if (! $head instanceof GitHubCommit) {
            throw new GitHubApiException("The branch [{$branch}] has no commits.");
        }

        return $head->sha;
    }
}
