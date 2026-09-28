<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

/**
 * Reads the line diff of a task workspace against the App default branch.
 *
 * A no-op implementation returns 0. A refused remote git command returns 0.
 */
interface TaskWorkspaceDiffReader
{
    /** @return array{additions: int, deletions: int}|null */
    public function lineChanges(Instance $instance, string $baseBranch): ?array;

    public function lineDiff(Instance $instance, string $baseBranch): int;

    public function hasCommitsSince(Instance $instance, string $since): bool;
}
