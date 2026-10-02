<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

final readonly class GitHubBranchPullRequest
{
    public function __construct(
        public string $url,
        public int $number,
        public GitHubPullRequestState $state,
    ) {}
}
