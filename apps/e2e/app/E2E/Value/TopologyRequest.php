<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * One issue and one worktree. The attempt identity is not part of the request:
 * acquisition mints it, and every later command reads the issue's active attempt.
 */
final readonly class TopologyRequest
{
    public string $worktree;

    public function __construct(
        public string $issue,
        string $worktree,
    ) {
        TopologyTarget::assertIssue($issue);

        if ($worktree === '' || str_contains($worktree, "\0") || ! str_starts_with($worktree, '/')) {
            throw new InvalidArgumentException('The worktree path must be absolute and safe.');
        }

        $resolved = realpath($worktree);
        if ($resolved === false || ! is_dir($resolved) || is_link($worktree)) {
            throw new InvalidArgumentException('The worktree path must identify a real directory.');
        }

        $this->worktree = $resolved;
    }
}
