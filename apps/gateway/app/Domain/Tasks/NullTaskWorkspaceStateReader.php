<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

final readonly class NullTaskWorkspaceStateReader implements TaskWorkspaceStateReader
{
    public function headCommit(AppInstance $instance): ?string
    {
        return null;
    }

    public function currentBranch(AppInstance $instance): ?string
    {
        return null;
    }

    public function definesComposerCheckScript(AppInstance $instance): bool
    {
        return false;
    }
}
