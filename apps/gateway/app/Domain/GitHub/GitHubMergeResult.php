<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/** GitHub's answer to a merge request. A refusal keeps GitHub's status and message (ADR 0203). */
final readonly class GitHubMergeResult
{
    public function __construct(
        public bool $merged,
        public ?string $sha,
        public int $status,
        public string $message,
    ) {}
}
