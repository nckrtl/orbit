<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewState;
use InvalidArgumentException;

/** Selection happens across heads, before exact-head eligibility. */
final readonly class TaskReviewSelection
{
    /** @param list<GitHubReview> $effective
     * @param  list<GitHubReview>  $requests
     * @param  list<GitHubReview>  $approvals
     */
    private function __construct(public array $effective, public array $requests, public array $approvals) {}

    /** @param list<GitHubReview> $reviews */
    public static function select(array $reviews, TaskReviewTrust $trust, ?string $head, bool $open): self
    {
        $latest = [];
        $seen = [];
        foreach ($reviews as $review) {
            if ($review->id < 1 || $review->reviewerId < 1 || isset($seen[$review->id])) {
                throw new InvalidArgumentException('Invalid review identity.');
            }
            $seen[$review->id] = true;
            if ($review->state === GitHubReviewState::Pending || $review->state === GitHubReviewState::Commented) {
                continue;
            }
            if ($review->submittedAt === null || $review->commitId === '') {
                throw new InvalidArgumentException('Incomplete decisive review.');
            }
            if (! $trust->valid || ! in_array($review->reviewerId, $trust->accountIds, true)) {
                continue;
            }
            $previous = $latest[$review->reviewerId] ?? null;
            if ($previous === null || self::compare($previous, $review) < 0) {
                $latest[$review->reviewerId] = $review;
            }
        }
        ksort($latest, SORT_NUMERIC);
        $effective = array_values($latest);
        $eligible = static fn (GitHubReview $review, GitHubReviewState $state): bool => $open && $head !== null
            && $review->commitId === $head && $review->state === $state;
        $requests = array_values(array_filter($effective, static fn (GitHubReview $review): bool => $eligible($review, GitHubReviewState::ChangesRequested)));
        usort($requests, self::compare(...));
        $approvals = array_values(array_filter($effective, static fn (GitHubReview $review): bool => $eligible($review, GitHubReviewState::Approved)));

        return new self($effective, $requests, $approvals);
    }

    private static function compare(GitHubReview $a, GitHubReview $b): int
    {
        return ($a->submittedAt <=> $b->submittedAt) ?: ($a->id <=> $b->id);
    }
}
