<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

interface TaskWorkspaceStateReader
{
    public function headCommit(AppInstance $instance): ?string;

    public function currentBranch(AppInstance $instance): ?string;

    public function isClean(AppInstance $instance): bool;

    public function definesComposerCheckScript(AppInstance $instance): bool;
}
