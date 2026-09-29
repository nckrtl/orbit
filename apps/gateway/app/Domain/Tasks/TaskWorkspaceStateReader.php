<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

interface TaskWorkspaceStateReader
{
    public function headCommit(Instance $instance): ?string;

    public function currentBranch(Instance $instance): ?string;

    public function definesComposerCheckScript(Instance $instance): bool;
}
