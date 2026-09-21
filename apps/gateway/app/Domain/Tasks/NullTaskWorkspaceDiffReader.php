<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

final readonly class NullTaskWorkspaceDiffReader implements TaskWorkspaceDiffReader
{
    public function lineChanges(AppInstance $instance, string $baseBranch): ?array
    {
        return null;
    }

    public function lineDiff(AppInstance $instance, string $baseBranch): int
    {
        return 0;
    }

    public function hasCommitsSince(AppInstance $instance, string $since): bool
    {
        return false;
    }
}
