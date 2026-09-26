<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * HEAD and the working-tree hash the Project check stores: the whole working tree, uncommitted and
 * untracked files included, without touching the Git index (ADR 0133).
 */
final readonly class TaskWorkspaceSnapshot
{
    public function __construct(
        public string $head,
        public string $tree,
    ) {}
}
