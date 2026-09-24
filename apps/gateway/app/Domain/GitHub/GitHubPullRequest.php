<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/**
 * The fields of a pull request that Orbit watches while its task group settles.
 */
final readonly class GitHubPullRequest
{
    /**
     * @param  bool|null  $mergeable  null while GitHub still computes whether the pull request merges
     */
    public function __construct(
        public GitHubPullRequestState $state,
        public ?bool $mergeable,
        public ?string $mergeableState,
        public ?string $headSha,
        public ?string $baseRef,
    ) {}

    /** Whether GitHub reports that the pull request conflicts with its base branch. */
    public function conflicts(): bool
    {
        return $this->mergeable === false || $this->mergeableState === 'dirty';
    }
}
