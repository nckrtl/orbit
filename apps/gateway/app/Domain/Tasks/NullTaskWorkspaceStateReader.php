<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

final readonly class NullTaskWorkspaceStateReader implements TaskWorkspaceStateReader
{
    public function headCommit(Instance $instance): ?string
    {
        return null;
    }

    public function currentBranch(Instance $instance): ?string
    {
        return null;
    }
}
