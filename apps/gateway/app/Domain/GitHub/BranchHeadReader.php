<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/** Reads the commit a branch points at now. */
interface BranchHeadReader
{
    /**
     * @return string the full SHA of the branch head
     *
     * @throws GitHubApiException when GitHub, the App, or its installation cannot answer
     */
    public function head(GitHubRepository $repository, string $branch): string;
}
