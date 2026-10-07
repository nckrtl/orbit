<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubCommit;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GreenCommit;
use App\Domain\GitHub\GreenCommitResolver;
use App\Domain\SourceControl\GitBranchName;
use InvalidArgumentException;

/**
 * Walks the branch's first-parent history from its head through the Gateway GitHub App. The same
 * rules as `bin/pr-head-check` decide a commit's check: a run of the required name for exactly that
 * `head_sha`, completed with `success`. A commit with no such run, such as one pushed together with
 * a newer commit, is never green. Every run of the required name must also come from GitHub Actions,
 * so another App with `checks: write` cannot report a commit green. Commits are read with a
 * `contents: read` token and check runs with a separate `checks: read` token, minted only when a
 * candidate needs one.
 */
final readonly class GitHubGreenCommitResolver implements GreenCommitResolver
{
    /** Bounds the check-run reads of one resolution. Older candidates wait for a newer green commit. */
    private const int CANDIDATES = 20;

    /** The only App whose check runs count. A run of the required name from any other App disqualifies the commit. */
    private const string CHECK_APP = 'github-actions';

    public function __construct(private GitHubAppStore $store, private GitHubApi $github) {}

    public function resolve(
        GitHubRepository $repository,
        string $branch,
        string $checkName,
        string $deployedSha,
        array $failedShas = [],
    ): ?GreenCommit {
        GitBranchName::validate($branch);
        if (trim($checkName) !== $checkName || $checkName === '' || strlen($checkName) > 255) {
            throw new InvalidArgumentException('The required check name is invalid.');
        }
        $deployed = $this->sha($deployedSha);
        $failed = array_fill_keys(array_map($this->sha(...), $failedShas), true);

        [$credentials, $installation] = $this->installation($repository);
        $contentsToken = $this->github->repositoryReadToken($credentials, $installation, $repository);
        $commits = [];
        foreach ($this->github->branchCommits($contentsToken, $repository, $branch) as $commit) {
            $commits[$commit->sha] ??= $commit;
        }

        $checksToken = null;
        $candidate = array_first($commits);
        for ($examined = 0; $candidate instanceof GitHubCommit && $examined < self::CANDIDATES; $examined++) {
            if ($candidate->sha === $deployed) {
                return null;
            }
            if (! isset($failed[$candidate->sha])) {
                $checksToken ??= $this->github->repositoryChecksToken($credentials, $installation, $repository);
                $run = $this->passingRun($this->github->checkRuns($checksToken, $repository, $candidate->sha, $checkName), $candidate->sha, $checkName);
                if ($run instanceof GitHubCheckRun) {
                    // On a first-parent chain, once a commit does not descend from the deployed one, no older commit does.
                    if (! $this->github->compareCommits($contentsToken, $repository, $deployed, $candidate->sha)->headDescendsFromBase()) {
                        return null;
                    }

                    return new GreenCommit($candidate->sha, $run);
                }
            }
            $parent = $candidate->firstParent();
            $candidate = $parent !== null ? ($commits[$parent] ?? null) : null;
        }

        return null;
    }

    /**
     * Every latest run of the required name must come from GitHub Actions and have passed on this exact
     * commit, and at least one must exist.
     *
     * @param  list<GitHubCheckRun>  $runs
     */
    private function passingRun(array $runs, string $sha, string $checkName): ?GitHubCheckRun
    {
        $required = array_values(array_filter($runs, static fn (GitHubCheckRun $run): bool => $run->name === $checkName));
        if ($required === [] || ! array_all($required, static fn (GitHubCheckRun $run): bool => $run->appSlug === self::CHECK_APP && $run->passedOn($sha))) {
            return null;
        }

        return $required[0];
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
        $installation = $this->github->repositoryInstallation($credentials, $repository)
            ?? throw new GitHubApiException('The Gateway GitHub App is not installed on '.$repository->owner.'/'.$repository->name.'.');

        return [$credentials, $installation];
    }

    private function sha(string $sha): string
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $sha) !== 1) {
            throw new InvalidArgumentException('A commit SHA must be 40 lowercase hexadecimal characters.');
        }

        return $sha;
    }
}
