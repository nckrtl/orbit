<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use InvalidArgumentException;

/**
 * Finds the newest commit of a branch that may be released ([GitHub App](/reference/github-app#find-the-newest-green-commit)).
 *
 * A commit qualifies when the required check run completed with `success` for exactly its SHA, it
 * strictly descends from the deployed commit, and it has not failed a release. Only commits the
 * branch itself pointed at are candidates, so a merged side branch's own commits never ship.
 */
interface GreenCommitResolver
{
    /**
     * @param  string|null  $deployedSha  the full SHA that is live now, or null when nothing is deployed yet
     * @param  list<string>  $failedShas  full SHAs that already failed a release
     * @return GreenCommit|null null when no newer commit qualifies
     *
     * @throws GitHubApiException when GitHub, the App, or its installation cannot answer completely
     * @throws InvalidArgumentException when the branch, check name, or a SHA is malformed
     */
    public function resolve(
        GitHubRepository $repository,
        string $branch,
        string $checkName,
        ?string $deployedSha,
        array $failedShas = [],
    ): ?GreenCommit;
}
