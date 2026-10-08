<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/** One open pull request from the repository's pull request list (ADR 0203). */
final readonly class GitHubListedPullRequest
{
    public function __construct(
        public int $number,
        public string $url,
        public string $title,
        public ?string $body,
        public int $authorId,
        public string $authorLogin,
        public string $headRef,
        public string $headSha,
        public ?string $headRepository,
        public string $baseRef,
        public bool $draft,
    ) {}
}
