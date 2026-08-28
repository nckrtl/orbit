<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class TopologyRequest
{
    public TopologyTarget $target;

    public string $worktree;

    public function __construct(
        public string $issue,
        string $worktree,
    ) {
        $this->target = new TopologyTarget($issue);

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
