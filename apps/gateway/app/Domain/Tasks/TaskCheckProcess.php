<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * A detached check process, identified by its ID and start time, and the tree it checks.
 */
final readonly class TaskCheckProcess
{
    public function __construct(
        public int $pid,
        public string $started,
        public string $head,
        public string $tree,
    ) {}
}
