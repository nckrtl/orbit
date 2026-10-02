<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReview;

/** Read results are separate from CI health and confer no lifecycle authority. */
final readonly class TaskReviewObservation
{
    /** @param list<GitHubReview> $reviews */
    public function __construct(
        public TaskReviewReadStatus $status,
        public ?GitHubRepository $repository = null,
        public ?int $number = null,
        public ?TaskReviewTrust $trust = null,
        public ?GitHubPullRequest $pullRequest = null,
        public array $reviews = [],
        public ?TaskReviewSelection $selection = null,
    ) {}
}
