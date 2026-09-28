<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

final readonly class NullTaskWorkspaceDiffReader implements TaskWorkspaceDiffReader
{
    public function lineChanges(Instance $instance, string $baseBranch): ?array
    {
        return null;
    }

    public function lineDiff(Instance $instance, string $baseBranch): int
    {
        return 0;
    }

    public function hasCommitsSince(Instance $instance, string $since): bool
    {
        return false;
    }
}
