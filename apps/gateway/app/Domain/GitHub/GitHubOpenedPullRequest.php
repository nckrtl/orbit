<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/** The pull request GitHub opened or reused for a task branch. */
final readonly class GitHubOpenedPullRequest
{
    public function __construct(
        public string $url,
        public ?int $number,
        public ?string $authorLogin,
    ) {}
}
