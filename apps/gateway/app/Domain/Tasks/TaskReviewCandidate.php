<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewComment;

/** Bounded source snapshot, not permission to dispatch. */
final readonly class TaskReviewCandidate
{
    /** @param list<GitHubReviewComment> $comments */
    public function __construct(
        public GitHubRepository $repository,
        public int $number,
        public string $head,
        public string $trustRevision,
        public GitHubReview $review,
        public array $comments,
    ) {}

    public function digest(): string
    {
        return hash('sha256', serialize([$this->repository, $this->number, $this->head, $this->trustRevision, $this->review, $this->comments]));
    }
}
