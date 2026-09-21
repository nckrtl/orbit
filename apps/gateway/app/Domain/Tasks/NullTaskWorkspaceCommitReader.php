<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

final readonly class NullTaskWorkspaceCommitReader implements TaskWorkspaceCommitReader
{
    public function commitTimes(AppInstance $instance): ?array
    {
        return null;
    }
}
