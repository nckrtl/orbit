<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/** The relation between two commits, read from `compare/{base}...{head}`. */
final readonly class GitHubCommitComparison
{
    public function __construct(
        public GitHubComparisonStatus $status,
        public string $baseSha,
        public string $mergeBaseSha,
    ) {}

    /** Whether the head strictly descends from the base: the base is the merge base and the head is ahead of it. */
    public function headDescendsFromBase(): bool
    {
        return $this->status === GitHubComparisonStatus::Ahead && $this->mergeBaseSha === $this->baseSha;
    }
}
