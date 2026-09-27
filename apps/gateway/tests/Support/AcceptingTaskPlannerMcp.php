<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\TaskPlannerMcp;
use App\Models\AppInstance;

/** Lets a reviewer start in tests without writing `.mcp.json` over SSH. */
final class AcceptingTaskPlannerMcp implements TaskPlannerMcp
{
    public function install(AppInstance $instance): bool
    {
        return true;
    }

    public function installWhenMissing(AppInstance $instance): bool
    {
        return true;
    }
}
