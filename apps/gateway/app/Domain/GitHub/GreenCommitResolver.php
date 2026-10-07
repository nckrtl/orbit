<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use InvalidArgumentException;

/**
 * Finds the newest commit of a branch that may be released ([GitHub App](/reference/github-app#find-the-newest-green-commit)).
 *
 * A commit qualifies when the required check run, created by GitHub Actions, completed with
 * `success` for exactly its SHA, it strictly descends from the deployed commit, and it has not
 * failed a release. Only commits the branch itself pointed at are candidates, so a merged side
 * branch's own commits never ship. There is no answer without a deployed commit: descent from it
 * is what rules out a downgrade, so the first release of a target is deployed by hand.
 */
interface GreenCommitResolver
{
    /**
     * @param  string  $deployedSha  the full SHA that is live now
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
        string $deployedSha,
        array $failedShas = [],
    ): ?GreenCommit;
}
