<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Models\Instance;

/** Lets a reviewer start in tests without writing `.mcp.json` over SSH. */
final class AcceptingTaskWorkspaceMcp implements TaskWorkspaceMcp
{
    public function installWhenMissing(Instance $instance): bool
    {
        return true;
    }
}
