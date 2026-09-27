<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

final readonly class GitHubPullRequestCommit
{
    public function __construct(
        public string $sha,
        public string $message,
        public ?string $committedAt,
    ) {}
}
