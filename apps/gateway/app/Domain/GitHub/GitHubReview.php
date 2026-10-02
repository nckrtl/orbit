<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use DateTimeImmutable;

/** Complete source data; eligibility and effective-decision selection belong to the watcher. */
final readonly class GitHubReview
{
    public function __construct(
        public int $id,
        public int $reviewerId,
        public string $reviewerLogin,
        public GitHubReviewState $state,
        public string $commitId,
        public ?DateTimeImmutable $submittedAt,
        public string $url,
        public string $body,
    ) {}
}
