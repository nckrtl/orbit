<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/** A releasable branch commit and the required check run that proved it. */
final readonly class GreenCommit
{
    public function __construct(
        public string $sha,
        public GitHubCheckRun $checkRun,
    ) {}
}
