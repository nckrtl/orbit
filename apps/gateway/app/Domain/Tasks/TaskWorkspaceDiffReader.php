<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

/**
 * Reads the line diff of a task workspace against the App default branch.
 *
 * A no-op implementation returns 0. A refused remote git command returns 0.
 */
interface TaskWorkspaceDiffReader
{
    public function lineDiff(AppInstance $instance, string $baseBranch): int;
}
