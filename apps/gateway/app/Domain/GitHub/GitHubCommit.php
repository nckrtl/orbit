<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/**
 * One commit of a branch listing, with its parents in Git order. The first parent is the
 * commit the branch pointed at before this one.
 */
final readonly class GitHubCommit
{
    /** @param  list<string>  $parents */
    public function __construct(
        public string $sha,
        public array $parents,
    ) {}

    public function firstParent(): ?string
    {
        return $this->parents[0] ?? null;
    }
}
