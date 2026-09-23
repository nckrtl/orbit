<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

/**
 * ADR 0124: gives a planner Orbit MCP through its workspace, whatever MCP the Node's agent is configured with.
 */
interface TaskPlannerMcp
{
    /** False when the workspace could not be prepared. A repository that tracks its own `.mcp.json` is left as it is. */
    public function install(AppInstance $instance): bool;
}
