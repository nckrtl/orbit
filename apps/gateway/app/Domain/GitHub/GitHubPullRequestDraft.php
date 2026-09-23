<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

final readonly class GitHubPullRequestDraft
{
    public function __construct(
        public string $head,
        public string $base,
        public string $title,
        public string $body,
    ) {}
}
